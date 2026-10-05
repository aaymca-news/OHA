<?php

namespace App\Support\Google;

use Carbon\CarbonImmutable;

/**
 * What Google Drive says about a file: enough to tell whether it changed since the
 * platform last read it, who changed it, and how to fetch its content.
 */
final readonly class DriveFile
{
    public const GOOGLE_DOC = 'application/vnd.google-apps.document';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const PDF = 'application/pdf';

    public function __construct(
        public string $id,
        public string $name,
        public string $mimeType,
        public int $version,
        public CarbonImmutable $modifiedAt,
        public ?string $editorEmail,
        public ?string $editorName,
        public bool $trashed = false,
    ) {}

    /** The format the platform keeps it in: a Google Doc is taken as a Word file. */
    public function format(): ?string
    {
        return match ($this->mimeType) {
            self::GOOGLE_DOC, self::DOCX => 'docx',
            self::PDF => 'pdf',
            default => null,
        };
    }

    /** A file name for the stored version, with the right extension. */
    public function fileName(): string
    {
        $base = preg_replace('/\.(docx|pdf)$/i', '', trim($this->name)) ?: 'ODP';

        return $base.'.'.$this->format();
    }
}
