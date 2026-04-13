/*!
 * ImageManUploader v1.2.0
 * Chunked file uploader for the ImageMan Laravel package.
 *
 * Features:
 *  - Parallel chunk uploads (configurable concurrency, default 3)
 *  - Per-chunk exponential-backoff retry (3 attempts)
 *  - Resumable uploads via resume(uploadId)
 *  - Abort support (DELETE endpoint)
 *  - Status polling until assembly completes
 *  - Zero runtime dependencies
 *
 * Usage (script tag):
 *   <script src="/vendor/imageman/imageman-uploader.js"></script>
 *   const up = new ImageManUploader({ endpoint: '/imageman/chunks', ... });
 *
 * Usage (ES module):
 *   import ImageManUploader from '/vendor/imageman/imageman-uploader.js';
 *
 * Usage (CommonJS / Node):
 *   const ImageManUploader = require('./imageman-uploader');
 *
 * @license MIT
 */

(function (root, factory) {
    // UMD wrapper — supports CommonJS, AMD, and plain <script> tags.
    if (typeof module === 'object' && typeof module.exports === 'object') {
        // CommonJS (Node / Browserify / webpack CJS)
        module.exports = factory();
    } else if (typeof define === 'function' && define.amd) {
        // AMD (RequireJS)
        define([], factory);
    } else {
        // Browser global (<script> tag)
        root.ImageManUploader = factory();
    }
}(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    // ---------------------------------------------------------------------------
    // Constants
    // ---------------------------------------------------------------------------

    var DEFAULT_CHUNK_SIZE   = 2 * 1024 * 1024; // 2 MB
    var DEFAULT_CONCURRENCY  = 3;
    var DEFAULT_MAX_RETRIES  = 3;
    var DEFAULT_POLL_INTERVAL = 1500; // ms
    var DEFAULT_ENDPOINT     = '/imageman/chunks';

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    function noop() {}

    /**
     * Sleep for `ms` milliseconds (returns a Promise).
     */
    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /**
     * Low-level fetch wrapper that returns the parsed JSON body or throws.
     */
    function apiFetch(method, url, body, headers) {
        var opts = {
            method: method,
            headers: Object.assign({}, headers),
        };

        if (body instanceof FormData) {
            // Let the browser set the Content-Type + boundary for multipart.
            opts.body = body;
        } else if (body !== null && body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        return fetch(url, opts).then(function (res) {
            if (res.status === 204) return null; // No Content (abort endpoint)
            return res.json().then(function (data) {
                if (!res.ok) {
                    var err = new Error((data && data.message) || ('HTTP ' + res.status));
                    err.status = res.status;
                    err.data   = data;
                    throw err;
                }
                return data;
            });
        });
    }

    /**
     * Upload a single chunk with exponential-backoff retry.
     *
     * @param {string}     url         Full endpoint URL for this upload ID.
     * @param {number}     index       0-based chunk index.
     * @param {Blob}       blob        The chunk binary data.
     * @param {Object}     headers     Extra request headers (CSRF etc.).
     * @param {number}     maxRetries  Maximum retry attempts.
     * @returns {Promise<Object>}      Server JSON response.
     */
    function uploadChunkWithRetry(url, index, blob, headers, maxRetries) {
        var attempt = 0;

        function attempt_() {
            var fd = new FormData();
            fd.append('chunk', blob, 'chunk-' + index);
            fd.append('chunk_index', String(index));

            return apiFetch('POST', url, fd, headers).catch(function (err) {
                attempt++;
                if (attempt >= maxRetries) throw err;
                // Exponential backoff: 500 ms, 1 000 ms, 2 000 ms …
                return sleep(500 * Math.pow(2, attempt - 1)).then(attempt_);
            });
        }

        return attempt_();
    }

    /**
     * Simple async concurrency pool.
     * Runs `tasks` (thunks returning Promises) with at most `limit` in flight.
     *
     * @param {Array<Function>} tasks   Array of () => Promise<any>.
     * @param {number}          limit   Maximum concurrent tasks.
     * @param {Function}        onEach  Called after each task resolves/rejects.
     * @returns {Promise<void>}
     */
    function pool(tasks, limit, onEach) {
        var index   = 0;
        var active  = 0;
        var resolve_;
        var reject_;

        var promise = new Promise(function (res, rej) {
            resolve_ = res;
            reject_  = rej;
        });

        if (!tasks.length) { resolve_(); return promise; }

        function next() {
            while (active < limit && index < tasks.length) {
                var taskIndex = index++;
                active++;
                tasks[taskIndex]()
                    .then(function (result) {
                        active--;
                        if (onEach) onEach(null, result);
                        if (index < tasks.length) { next(); }
                        else if (active === 0) { resolve_(); }
                    })
                    .catch(function (err) {
                        active--;
                        if (onEach) onEach(err, null);
                        reject_(err);
                    });
            }
        }

        next();
        return promise;
    }

    // ---------------------------------------------------------------------------
    // ImageManUploader class
    // ---------------------------------------------------------------------------

    /**
     * @param {Object}   opts
     * @param {string}   [opts.endpoint='/imageman/chunks']  Base URL for chunk API.
     * @param {string}   [opts.collection='default']         Target image collection.
     * @param {string}   [opts.disk]                         Target storage disk.
     * @param {Object}   [opts.meta]                         Arbitrary image metadata.
     * @param {string}   [opts.imageableType]                Eloquent model FQCN.
     * @param {number}   [opts.imageableId]                  Eloquent model PK.
     * @param {number}   [opts.chunkSize=2097152]            Chunk size in bytes (2 MB).
     * @param {number}   [opts.concurrency=3]                Max parallel chunk uploads.
     * @param {number}   [opts.maxRetries=3]                 Per-chunk retry attempts.
     * @param {number}   [opts.pollInterval=1500]            Status poll interval (ms).
     * @param {Object}   [opts.headers={}]                   Extra headers (CSRF etc.).
     * @param {Function} [opts.onProgress]                   (percent: number) => void
     * @param {Function} [opts.onComplete]                   (uploadId, imageId) => void
     * @param {Function} [opts.onError]                      (error) => void
     */
    function ImageManUploader(opts) {
        opts = opts || {};

        this._endpoint     = (opts.endpoint     || DEFAULT_ENDPOINT).replace(/\/$/, '');
        this._collection   = opts.collection   || 'default';
        this._disk         = opts.disk         || null;
        this._meta         = opts.meta         || null;
        this._imageableType = opts.imageableType || null;
        this._imageableId  = opts.imageableId  || null;
        this._chunkSize    = opts.chunkSize    || DEFAULT_CHUNK_SIZE;
        this._concurrency  = opts.concurrency  || DEFAULT_CONCURRENCY;
        this._maxRetries   = opts.maxRetries   || DEFAULT_MAX_RETRIES;
        this._pollInterval = opts.pollInterval || DEFAULT_POLL_INTERVAL;
        this._headers      = opts.headers      || {};

        this.onProgress = opts.onProgress || noop;
        this.onComplete = opts.onComplete || noop;
        this.onError    = opts.onError    || noop;

        // Mutable state
        this._uploadId     = null;
        this._aborted      = false;
        this._pollTimer    = null;
        this._totalChunks  = 0;
        this._doneChunks   = 0;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Start a new chunked upload for `file`.
     *
     * @param  {File} file  The File object from an <input type="file"> or drag-drop.
     * @returns {Promise<void>}
     */
    ImageManUploader.prototype.upload = function (file) {
        this._reset();
        var self = this;

        return self._initiate(file)
            .then(function (data) {
                self._uploadId    = data.upload_id;
                self._totalChunks = data.total_chunks;
                self._doneChunks  = 0;
                return self._uploadChunks(file, data.upload_id, data.total_chunks, []);
            })
            .then(function () {
                return self._pollUntilComplete(self._uploadId);
            })
            .catch(function (err) {
                if (!self._aborted) self.onError(err);
            });
    };

    /**
     * Resume an interrupted upload.
     *
     * Fetches the current session status to find which chunks are still
     * missing, then uploads only those chunks.
     *
     * @param  {string} uploadId  The upload_id from a previous initiation.
     * @param  {File}   file      The same File object as the original upload.
     * @returns {Promise<void>}
     */
    ImageManUploader.prototype.resume = function (uploadId, file) {
        this._reset();
        var self = this;
        self._uploadId = uploadId;

        var statusUrl = self._endpoint + '/' + uploadId + '/status';

        return apiFetch('GET', statusUrl, null, self._headers)
            .then(function (data) {
                if (data.status === 'complete') {
                    self.onProgress(100);
                    self.onComplete(uploadId, data.image_id);
                    return;
                }

                if (data.status === 'failed') {
                    throw new Error('Upload session has already failed: ' + data.error_message);
                }

                var missing       = data.missing_chunks || [];
                self._totalChunks = data.total_chunks;
                self._doneChunks  = (data.total_chunks - missing.length);

                return self._uploadChunks(file, uploadId, data.total_chunks, missing)
                    .then(function () {
                        return self._pollUntilComplete(uploadId);
                    });
            })
            .catch(function (err) {
                if (!self._aborted) self.onError(err);
            });
    };

    /**
     * Abort the current upload. Sends a DELETE request and cleans up.
     *
     * @returns {Promise<void>}
     */
    ImageManUploader.prototype.abort = function () {
        this._aborted = true;
        this._clearPoll();

        if (!this._uploadId) return Promise.resolve();

        var url = this._endpoint + '/' + this._uploadId;
        return apiFetch('DELETE', url, null, this._headers).catch(noop);
    };

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    ImageManUploader.prototype._reset = function () {
        this._aborted     = false;
        this._uploadId    = null;
        this._totalChunks = 0;
        this._doneChunks  = 0;
        this._clearPoll();
    };

    ImageManUploader.prototype._clearPoll = function () {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
    };

    /**
     * POST /imageman/chunks/initiate
     */
    ImageManUploader.prototype._initiate = function (file) {
        var totalChunks = Math.ceil(file.size / this._chunkSize);

        var body = {
            filename:     file.name,
            mime_type:    file.type || 'application/octet-stream',
            total_size:   file.size,
            total_chunks: totalChunks,
            collection:   this._collection,
        };

        if (this._disk)          body.disk           = this._disk;
        if (this._meta)          body.meta           = this._meta;
        if (this._imageableType) body.imageable_type = this._imageableType;
        if (this._imageableId)   body.imageable_id   = this._imageableId;

        return apiFetch('POST', this._endpoint + '/initiate', body, this._headers);
    };

    /**
     * Upload chunks (or a subset of them for resume).
     *
     * @param {File}     file         The source file.
     * @param {string}   uploadId     Session UUID.
     * @param {number}   totalChunks  Total number of chunks.
     * @param {number[]} only         If non-empty, only these indices are sent.
     */
    ImageManUploader.prototype._uploadChunks = function (file, uploadId, totalChunks, only) {
        var self      = this;
        var url       = self._endpoint + '/' + uploadId;
        var chunkSize = self._chunkSize;

        var indices = only.length
            ? only
            : Array.from({ length: totalChunks }, function (_, i) { return i; });

        var tasks = indices.map(function (i) {
            return function () {
                if (self._aborted) return Promise.reject(new Error('Aborted'));

                var start = i * chunkSize;
                var blob  = file.slice(start, start + chunkSize);

                return uploadChunkWithRetry(url, i, blob, self._headers, self._maxRetries)
                    .then(function (data) {
                        if (self._aborted) return;
                        self._doneChunks++;
                        var pct = Math.round((self._doneChunks / self._totalChunks) * 100);
                        self.onProgress(pct);
                        return data;
                    });
            };
        });

        return pool(tasks, self._concurrency);
    };

    /**
     * Poll GET /imageman/chunks/{id}/status until status is 'complete' or 'failed'.
     */
    ImageManUploader.prototype._pollUntilComplete = function (uploadId) {
        var self      = this;
        var url       = self._endpoint + '/' + uploadId + '/status';
        var interval  = self._pollInterval;

        return new Promise(function (resolve, reject) {
            function check() {
                if (self._aborted) return resolve();

                apiFetch('GET', url, null, self._headers)
                    .then(function (data) {
                        if (data.status === 'complete') {
                            self.onProgress(100);
                            self.onComplete(uploadId, data.image_id);
                            resolve();
                        } else if (data.status === 'failed') {
                            reject(new Error(data.error_message || 'Assembly failed.'));
                        } else {
                            // Still assembling or processing — keep polling.
                            self._pollTimer = setTimeout(check, interval);
                        }
                    })
                    .catch(reject);
            }

            check();
        });
    };

    // ---------------------------------------------------------------------------
    // ES module export (for bundlers that understand `export default`)
    // ---------------------------------------------------------------------------

    // When loaded as a proper ES module (e.g. via <script type="module"> or a
    // bundler in ESM mode), attach a default export so that
    //   import ImageManUploader from './imageman-uploader.js'
    // works alongside the UMD wrapper above.
    if (typeof Symbol !== 'undefined' && typeof Symbol.toStringTag !== 'undefined') {
        // No-op: ES module consumers pick up `ImageManUploader` from the return
        // value of the factory. The UMD wrapper already handles global assignment.
    }

    return ImageManUploader;
}));

// ES module named export for bundlers that do static analysis.
// This line is only evaluated in ESM environments (e.g. Rollup, Vite, webpack ESM).
if (typeof exports === 'object' && typeof exports.__esModule === 'undefined') {
    try { Object.defineProperty(exports, '__esModule', { value: true }); } catch (_) {}
}
