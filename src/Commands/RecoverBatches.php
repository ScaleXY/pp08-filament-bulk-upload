<?php

namespace ScaleXY\FilamentBulkUpload\Commands;

use Illuminate\Console\Command;
use ScaleXY\FilamentBulkUpload\Jobs\ProcessBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadBatch;

class RecoverBatches extends Command
{
    protected $signature = 'bulk-upload:recover';

    protected $description = 'Redispatch pending or stalled media attachment batches';

    public function handle(): int
    {
        UploadBatch::where('status', 'pending')->orWhere(fn ($query) => $query->where('status', 'processing')->where('heartbeat_at', '<', now()->subMinutes(15)))
            ->chunkById(100, function ($batches) {
                foreach ($batches as $batch) {
                    $settings = $batch->session->settings;
                    ProcessBatch::dispatch($batch->id)->onConnection($settings['connection'])->onQueue($settings['queue']);
                }
            });

        return self::SUCCESS;
    }
}
