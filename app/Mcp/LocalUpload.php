<?php

namespace App\Mcp;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class LocalUpload
{
    public static function fromPath(string $path, string $field, int $maxBytes = 10485760): UploadedFile
    {
        $resolved = realpath($path);
        $size = $resolved && is_file($resolved) ? filesize($resolved) : false;
        if (! str_starts_with($path, '/') || ! $resolved || ! is_readable($resolved) || $size === false || $size > $maxBytes) {
            throw ValidationException::withMessages([$field => 'Choose a readable local file within the size limit.']);
        }

        return new UploadedFile($resolved, basename($path), test: true);
    }
}
