<?php

namespace ScaleXY\FilamentBulkUpload\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ScaleXY\FilamentBulkUpload\Models\UploadBatch;
use ScaleXY\FilamentBulkUpload\Support\Collections;
use ScaleXY\FilamentBulkUpload\Support\UploadDisk;
use Throwable;

class ProcessBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public string $batchId) {}

    public function handle(Collections $collections): void
    {
        $batch = UploadBatch::find($this->batchId);
        if ($batch) {
            app(UploadDisk::class)->run($batch->session->settings, fn () => $this->process($collections));
        }
    }

    protected function process(Collections $collections): void
    {
        $batch = UploadBatch::find($this->batchId);
        if (! $batch || in_array($batch->status, ['completed', 'failed', 'discarded'])) {
            return;
        }
        $lock = Cache::store(config('filament-bulk-upload.lock_store'))->lock('bulk-media:'.hash('sha256', $batch->model_type.':'.$batch->model_id.':'.$batch->collection), 600);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $batch->refresh();
            if (in_array($batch->status, ['completed', 'failed', 'discarded'])) {
                return;
            }
            $session = $batch->session;
            $record = $batch->model_type::query()->find($batch->model_id);
            if (! $record) {
                throw new \RuntimeException('The target record was deleted.');
            }
            // One active batch per record/collection; detect changes made through other media fields.
            $current = $collections->snapshot($record, $batch->collection);
            $expected = $collections->normalizeSnapshot($session->snapshot);
            $ours = $record->media()->where('collection_name', $batch->collection)->get()->filter(fn ($media) => $media->getCustomProperty('bulk_upload_session') === $session->id)->pluck('id')->map('strval')->all();
            $external = array_values(array_filter($current, fn ($entry) => ! in_array($entry['id'], $ours, true)));
            $expectedExternal = array_values(array_filter($expected, fn ($entry) => ! in_array($entry['id'], $ours, true) && ! in_array($entry['id'], $batch->removals, true)));
            foreach ($current as $entry) {
                if (in_array($entry['id'], $batch->removals, true) && ! in_array($entry, $expected, true)) {
                    throw new \RuntimeException('Media selected for removal changed while this batch was pending.');
                }
            }
            // Before removals run, their presence is expected too.
            $external = array_values(array_filter($external, fn ($entry) => ! in_array($entry['id'], $batch->removals, true)));
            if ($external !== $expectedExternal) {
                throw new \RuntimeException('The media collection changed while this batch was pending. Reload and reconcile the collection.');
            }
            $pendingIds = $session->files()->where('status', 'uploaded')->pluck('id');
            $recoveredCount = $record->media()->where('collection_name', $batch->collection)->whereIn('custom_properties->bulk_upload_id', $pendingIds)->count();
            $remaining = $pendingIds->count() - $recoveredCount;
            $stillPresentRemovals = $record->media()->whereIn('id', $batch->removals)->where('collection_name', $batch->collection)->count();
            $collections->assertCapacity($record, $batch->collection, $remaining, $stillPresentRemovals);
            $batch->update(['status' => 'processing', 'heartbeat_at' => now(), 'error' => null]);
            foreach ($batch->removals as $id) {
                $record->media()->where('collection_name', $batch->collection)->find($id)?->delete();
            }
            $deadline = microtime(true) + 200;
            foreach ($session->files()->where('status', 'uploaded')->orderBy('created_at')->orderBy('id')->limit(25)->get() as $file) {
                $batch->update(['heartbeat_at' => now()]);
                try {
                    $disk = Storage::disk($session->settings['disk']);
                    $media = $record->media()->where('collection_name', $batch->collection)->where('custom_properties->bulk_upload_id', $file->id)->first();
                    if ($media && (! Storage::disk($media->disk)->exists($media->getPathRelativeToRoot()) || Storage::disk($media->disk)->size($media->getPathRelativeToRoot()) !== $file->size)) {
                        $media->delete();
                        $media = null;
                    }
                    if (! $media) {
                        if (! $disk->exists($file->object_key)) {
                            throw new \RuntimeException('The temporary upload is missing.');
                        }
                        $media = $record->addMediaFromDisk($file->object_key, $session->settings['disk'])
                            ->usingFileName($file->name)->usingName(pathinfo($file->name, PATHINFO_FILENAME))
                            ->withCustomProperties(['bulk_upload_id' => $file->id, 'bulk_upload_session' => $session->id])
                            ->preservingOriginal()->toMediaCollection($batch->collection, $session->settings['disk']);
                    }
                    $position = array_search('upload:'.$file->id, $batch->ordering, true);
                    $media->update(['order_column' => $position + 1]);
                    $file->update(['status' => 'attached', 'media_id' => (string) $media->id, 'error' => null]);
                    // A crash here is safe: cleanup also removes attached originals.
                    $disk->delete($file->object_key);
                } catch (Throwable $exception) {
                    report($exception);
                    $file->update(['status' => 'failed', 'error' => 'Attachment failed. Check application logs, then retry.']);
                }
                if (microtime(true) > $deadline) {
                    break;
                }
            }
            if ($session->files()->where('status', 'uploaded')->exists()) {
                $batch->update(['status' => 'pending']);
                self::dispatch($batch->id)->onConnection($session->settings['connection'])->onQueue($session->settings['queue']);

                return;
            }
            DB::transaction(function () use ($batch, $record, $session, $collections) {
                foreach ($batch->ordering as $position => $reference) {
                    if (str_starts_with($reference, 'media:')) {
                        $record->media()->where('collection_name', $batch->collection)->whereKey(substr($reference, 6))->update(['order_column' => $position + 1]);
                    }
                }
                $session->update(['snapshot' => $collections->snapshot($record, $batch->collection)]);
                $batch->update(['status' => $session->files()->where('status', 'failed')->exists() ? 'failed' : 'completed', 'heartbeat_at' => now()]);
            });
        } catch (Throwable $exception) {
            report($exception);
            $batch->update(['status' => 'failed', 'error' => $exception instanceof ValidationException ? 'The collection no longer has capacity for this batch.' : ($exception instanceof \RuntimeException ? $exception->getMessage() : 'Batch processing failed. Check application logs.')]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Infrastructure failures remain recoverable through the scheduled dispatcher.
        UploadBatch::whereKey($this->batchId)->whereNotIn('status', ['completed', 'failed', 'discarded'])->update(['status' => 'pending']);
    }
}
