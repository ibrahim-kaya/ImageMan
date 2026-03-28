<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded image fails one or more validation constraints
 * (e.g. file too large, dimensions out of range, wrong aspect ratio).
 */
class ValidationException extends RuntimeException
{
    /** @var array<string, string> */
    protected array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;

        parent::__construct(
            'Image validation failed: ' . implode('; ', $errors)
        );
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
