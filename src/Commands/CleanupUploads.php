<?php

namespace ScaleXY\FilamentBulkUpload\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use ScaleXY\FilamentBulkUpload\Support\S3;
use ScaleXY\FilamentBulkUpload\Support\UploadDisk;

class CleanupUploads extends Command
{
    protected $signature = 'bulk-upload:cleanup';

    protected $description = 'Remove expired abandoned uploads and completed temporary originals';

    public function handle(S3 $s3): int
    {
        UploadSession::where('expires_at', '<', now())->whereIn('status', ['open', 'expired', 'submitted'])->chunkById(100, function ($sessions) use ($s3) {
            foreach ($sessions as $candidate) {
                DB::transaction(function () use ($candidate, $s3) {
                    $session = UploadSession::query()->lockForUpdate()->find($candidate->id);
                    if (! $session || $session->expires_at->isFuture()) {
                        return;
                    }
                    if ($session->status === 'submitted' && $session->batch?->status !== 'completed') {
                        return;
                    }
                    app(UploadDisk::class)->run($session->settings, function () use ($session, $s3) {
                        $disk = Storage::disk($session->settings['disk']);
                        foreach ($session->files as $file) {
                            $s3->abort($file);
                            $disk->delete([$file->object_key, str_ends_with($file->object_key, '.sealed') ? substr($file->object_key, 0, -7) : $file->object_key.'.sealed']);
                            $file->update(['multipart_id' => null]);
                        }
                        $disk->deleteDirectory(trim(config('filament-bulk-upload.prefix'), '/').'/'.$session->id);
                        $session->update(['status' => 'expired']);
                    });
                });
            }
        });

        return self::SUCCESS;
    }
}
