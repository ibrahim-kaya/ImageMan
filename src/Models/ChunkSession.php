<?php

namespace IbrahimKaya\ImageMan\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the imageman_chunk_sessions table.
 *
 * Each record represents one chunked upload session initiated by a browser
 * client. The session tracks which chunks have been received, where they are
 * stored temporarily, and the final outcome (image_id once complete).
 *
 * @property string      $id
 * @property string      $original_filename
 * @property string      $mime_type
 * @property int         $total_size
 * @property int         $total_chunks
 * @property array       $received_chunks
 * @property string      $chunk_directory
 * @property string|null $target_disk
 * @property string      $target_collection
 * @property array|null  $target_meta
 * @property string|null $imageable_type
 * @property int|null    $imageable_id
 * @property string      $status
 * @property int|null    $image_id
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $last_chunk_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ChunkSession extends Model
{
    protected $table      = 'imageman_chunk_sessions';
    protected $keyType    = 'string';
    public    $incrementing = false; // UUID primary key

    protected $fillable = [
        'id',
        'original_filename',
        'mime_type',
        'total_size',
        'total_chunks',
        'received_chunks',
        'chunk_directory',
        'target_disk',
        'target_collection',
        'target_meta',
        'imageable_type',
        'imageable_id',
        'status',
        'image_id',
        'error_message',
        'last_chunk_at',
    ];

    protected $casts = [
        'total_size'      => 'integer',
        'total_chunks'    => 'integer',
        'imageable_id'    => 'integer',
        'image_id'        => 'integer',
        'received_chunks' => 'array',
        'target_meta'     => 'array',
        'last_chunk_at'   => 'datetime',
    ];

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Whether all expected chunks have been received.
     */
    public function isComplete(): bool
    {
        return count($this->received_chunks ?? []) >= $this->total_chunks;
    }

    /**
     * Return the list of chunk indices that have NOT yet been received.
     * Useful for debugging and the status endpoint.
     *
     * @return int[]
     */
    public function missingChunks(): array
    {
        $all      = range(0, $this->total_chunks - 1);
        $received = $this->received_chunks ?? [];

        return array_values(array_diff($all, $received));
    }

    /**
     * Return the number of bytes received so far (approximate).
     * Calculated as: received_chunk_count × chunk_size_hint.
     * Not exact when the last chunk is smaller than the rest.
     */
    public function receivedBytes(int $chunkSize): int
    {
        return count($this->received_chunks ?? []) * $chunkSize;
    }
}
