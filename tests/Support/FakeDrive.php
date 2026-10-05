<?php

namespace Tests\Support;

use App\Support\Google\DriveFile;
use App\Support\Google\DriveUnavailable;
use App\Support\Google\GoogleDrive;
use Carbon\CarbonImmutable;

/**
 * Google Drive, as the tests need it: one document whose content and version the
 * test changes, as staff editing it in Google Docs would.
 */
final class FakeDrive extends GoogleDrive
{
    public bool $connected = true;

    public ?string $failure = null;

    public int $version = 1;

    public string $content = 'first draft';

    public ?string $editor = null;

    public CarbonImmutable $modifiedAt;

    public int $reads = 0;

    public function __construct()
    {
        $this->modifiedAt = CarbonImmutable::now()->subHour();
    }

    /** Someone edits the document in Google Docs. */
    public function edit(string $content, ?string $by, int $minutesAgo = 30): void
    {
        $this->content = $content;
        $this->editor = $by;
        $this->version++;
        $this->modifiedAt = CarbonImmutable::now()->subMinutes($minutesAgo);
    }

    public function configured(): bool
    {
        return $this->connected;
    }

    public function serviceAccountEmail(): ?string
    {
        return 'oha-platform@aaymca-oha.iam.gserviceaccount.com';
    }

    public function file(string $fileId): DriveFile
    {
        if ($this->failure !== null) {
            throw new DriveUnavailable($this->failure);
        }

        return new DriveFile($fileId, 'Zambia ODP 2026', DriveFile::GOOGLE_DOC, $this->version, $this->modifiedAt, $this->editor, $this->editor !== null ? 'Editor '.$this->editor : null);
    }

    public function content(DriveFile $file): string
    {
        $this->reads++;

        return "PK fake docx: {$this->content}";
    }
}
