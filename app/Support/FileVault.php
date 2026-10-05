<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Keeps OHA files on the private disk, named by their SHA-256. A stored file is
 * never overwritten or changed: storing the same bytes again reuses the copy.
 */
final class FileVault
{
    /**
     * @return array{disk: string, path: string, sha256: string, size_bytes: int}
     */
    public static function store(string $sourcePath, string $directory, string $extension): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("Cannot read the file to store: {$sourcePath}");
        }

        $disk = (string) config('oha.disk');
        $sha256 = (string) hash_file('sha256', $sourcePath);
        $path = trim($directory, '/').'/'.$sha256.'.'.strtolower($extension);

        if (! Storage::disk($disk)->exists($path)) {
            $stream = fopen($sourcePath, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Cannot open the file to store: {$sourcePath}");
            }
            try {
                Storage::disk($disk)->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }
        }

        return ['disk' => $disk, 'path' => $path, 'sha256' => $sha256, 'size_bytes' => (int) filesize($sourcePath)];
    }
}
