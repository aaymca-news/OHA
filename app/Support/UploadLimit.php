<?php

namespace App\Support;

/**
 * The largest file that can really be uploaded: the platform's own limit
 * (oha.max_upload_kb), or PHP's on this server (upload_max_filesize, post_max_size),
 * whichever is smaller. PHP drops a file over its limit before the platform sees it,
 * so every upload box shows this figure, checks it before sending, and says it when a
 * file is refused.
 */
final class UploadLimit
{
    /** In kilobytes. */
    public static function kb(): int
    {
        return max(1, min(
            (int) config('oha.max_upload_kb'),
            self::iniKb('upload_max_filesize'),
            self::iniKb('post_max_size'),
        ));
    }

    /** "20 MB", "2 MB", "1.5 MB". */
    public static function label(): string
    {
        return Viz::figure(self::kb() / 1024, 1).' MB';
    }

    /**
     * The validation rule for an uploaded document: a file within the limit in force.
     * Use with messages() for the plain "too large" wording.
     *
     * @return list<string>
     */
    public static function rule(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'max:'.self::kb()];
    }

    /** What a person is told when a file is too large. */
    public static function tooBig(): string
    {
        return 'The file is too large: the most that can be uploaded is '.self::label().'. Make it smaller (for example, save a scanned PDF at a lower quality), or ask an Administrator to raise the limit.';
    }

    /**
     * Validation messages for a file field: too large for the platform, or dropped by PHP
     * on the way (it then arrives as "failed to upload").
     *
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [$field.'.max' => self::tooBig(), $field.'.uploaded' => self::tooBig()];
    }

    /** A php.ini size ("2M", "512K", "1G", "0" for no limit) in kilobytes. */
    private static function iniKb(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (float) $value;

        return (int) match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024,
            'M' => $number * 1024,
            'K' => $number,
            default => $number / 1024,
        };
    }
}
