<?php

namespace App\Console\Commands;

use App\Actions\Oha\TakeOdpFromDrive;
use App\Enums\ArtefactKind;
use App\Models\Artefact;
use App\Models\DriveSyncRun;
use App\Support\Google\GoogleDrive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes the changes made in Google Drive to every linked ODP as new versions; once
 * the ODP is signed, notes them as changes after signing instead. Scheduled every five
 * minutes once the platform has access to Google Drive; each run is recorded, so the
 * dashboard can show when it last worked.
 */
#[Signature('oha:sync-drive')]
#[Description('Take changes made to the ODPs in Google Drive as new versions')]
class SyncOdpsFromDrive extends Command
{
    public function handle(GoogleDrive $drive, TakeOdpFromDrive $take): int
    {
        $run = DriveSyncRun::query()->create(['started_at' => now()]);

        if (! $drive->configured()) {
            $run->update(['finished_at' => now(), 'error' => 'Not connected: no Google service account key is configured (GOOGLE_SERVICE_ACCOUNT_JSON).']);
            $this->warn((string) $run->error);

            return self::SUCCESS;
        }

        // Signed ODPs too: a change after signing is noted for Stage 2 (never a version).
        $odps = Artefact::query()->where('kind', ArtefactKind::Odp)->whereNotNull('drive_file_id')
            ->with('assessment.movement')->orderBy('id')->get();

        try {
            foreach ($odps as $odp) {
                $result = $take->handle($odp);
                $run->increment('checked');
                match ($result['outcome']) {
                    TakeOdpFromDrive::SAVED => $run->increment('saved'),
                    TakeOdpFromDrive::FAILED => $run->increment('failed'),
                    default => null,
                };
                $this->line("{$odp->assessment->movement->name}: {$result['message']}");
            }
        } catch (Throwable $e) {
            $run->update(['finished_at' => now(), 'error' => $e->getMessage()]);
            report($e);

            return self::FAILURE;
        }

        $run->update(['finished_at' => now()]);
        $this->info("Checked {$run->checked}, saved {$run->saved} new ".($run->saved === 1 ? 'version' : 'versions').", {$run->failed} could not be read.");

        return self::SUCCESS;
    }
}
