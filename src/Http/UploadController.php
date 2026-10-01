<?php

namespace ScaleXY\FilamentBulkUpload\Http;

use Aws\S3\Exception\S3Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ScaleXY\FilamentBulkUpload\Jobs\ProcessBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadFile;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use ScaleXY\FilamentBulkUpload\Support\Access;
use ScaleXY\FilamentBulkUpload\Support\Collections;
use ScaleXY\FilamentBulkUpload\Support\S3;

class UploadController
{
    public function __construct(protected Access $access, protected S3 $s3, protected Collections $collections) {}

    public function store(Request $request): array
    {
        $request->validate(['token' => 'required|string']);
        try {
            $descriptor = Crypt::decrypt($request->string('token')->toString());
        } catch (\Throwable) {
            abort(403);
        }
        abort_unless($descriptor['owner'] === $this->access->owner() && $descriptor['expires'] > time(), 403);
        $this->access->restoreTenant($descriptor['tenant']);
        abort_unless($descriptor['tenant'] === $this->access->tenant(), 403);
        $settings = $descriptor['settings'];
        $this->access->authorize($settings);
        $record = isset($settings['record']) ? $this->access->record($settings) : new $settings['model'];
        $this->collections->assertCapacity($record, $settings['collection'], 0);
        if ($record->exists) {
            $active = UploadBatch::query()
                ->where('model_type', $settings['model'])->where('model_id', (string) $record->getKey())
                ->where('collection', $settings['collection'])->whereIn('status', ['pending', 'processing', 'failed'])
                ->whereHas('session', fn ($query) => $query->where('owner', $descriptor['owner'])->where('tenant', $descriptor['tenant']))
                ->latest()->first();
            if ($active && ($active->session->settings['field'] ?? null) === ($settings['field'] ?? null)) {
                return ['session' => $active->session_id, 'order' => $active->ordering, 'remove' => $active->removals];
            }
        }
        $snapshot = $record->exists ? $this->collections->snapshot($record, $settings['collection']) : [];
        $session = UploadSession::create([
            'id' => (string) Str::uuid(), 'owner' => $descriptor['owner'], 'tenant' => $descriptor['tenant'],
            'settings' => $settings, 'snapshot' => $snapshot,
            'expires_at' => now()->addHours(config('filament-bulk-upload.session_hours', 24)),
        ]);

        return ['session' => $session->id, 'order' => array_map(fn ($media) => 'media:'.$media['id'], $snapshot)];
    }

    public function register(Request $request, string $session): array
    {
        $data = $request->validate(['files' => 'required|array|min:1|max:1000', 'files.*.name' => 'required|string|max:255', 'files.*.size' => 'required|integer|min:1']);

        return $this->withSession($session, true, function ($session) use ($data) {
            $settings = $session->settings;
            $active = $session->files()->where('status', '!=', 'cancelled')->count();
            abort_if($active + count($data['files']) > $settings['max_files'], 422, 'Too many files.');
            $record = isset($settings['record']) ? $this->access->record($settings) : new $settings['model'];
            // Count-limited collections may have removals staged later; the definitive check is at save.
            $record->registerMediaCollections();
            abort_if($record->getRegisteredMediaCollections()->firstWhere('name', $settings['collection'])?->singleFile, 422);
            $files = [];
            foreach ($data['files'] as $item) {
                abort_if($item['size'] > $settings['max_bytes'], 422, 'File exceeds the configured size limit.');
                $id = (string) Str::uuid();
                $name = basename(str_replace('\\', '/', $item['name']));
                abort_if($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name), 422, 'Invalid filename.');
                $file = $session->files()->create(['id' => $id, 'name' => $name, 'size' => $item['size'],
                    'object_key' => trim(config('filament-bulk-upload.prefix'), '/').'/'.$session->id.'/'.$id]);
                $files[] = ['id' => $file->id, 'name' => $file->name, 'size' => $file->size];
            }

            return ['files' => $files];
        });
    }

    public function operation(Request $request, string $session, string $file, string $operation): array
    {
        return $this->withSession($session, true, function ($session) use ($request, $file, $operation) {
            $file = $session->files()->findOrFail($file);
            if ($operation === 'verify' && $file->status === 'uploaded') {
                return ['id' => $file->id];
            }
            abort_unless($file->status === 'registered', 409, 'Upload is no longer writable.');
            switch ($operation) {
                case 'put':
                    abort_if($file->multipart_id, 409);

                    return $this->s3->put($file);
                case 'multipart':
                    if (! $file->multipart_id) {
                        $file->update(['multipart_id' => $this->s3->createMultipart($file)]);
                    }

                    return ['uploadId' => $file->multipart_id, 'key' => $file->id];
                case 'part':
                    $data = $request->validate(['partNumber' => 'required|integer|min:1|max:10000']);
                    abort_unless($file->multipart_id, 409);

                    return $this->s3->signPart($file, $data['partNumber']);
                case 'parts':
                    abort_unless($file->multipart_id, 409);

                    return ['parts' => $this->s3->listParts($file)];
                case 'complete':
                    $data = $request->validate(['parts' => 'required|array|min:1|max:10000', 'parts.*.PartNumber' => 'required|integer|min:1|max:10000|distinct', 'parts.*.ETag' => 'required|string|max:100']);
                    // Completion response can be lost. A completed object is enough to proceed to verification.
                    if ($file->multipart_id) {
                        $parts = $data['parts'];
                        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
                        try {
                            $this->s3->complete($file, $parts);
                        } catch (S3Exception $exception) {
                            if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                                throw $exception;
                            }
                            abort_unless(Storage::disk($session->settings['disk'])->exists($file->object_key), 409);
                        }
                        $file->update(['multipart_id' => null]);
                    }

                    return ['location' => $file->id];
                case 'abort':
                    $this->s3->abort($file);
                    $file->update(['multipart_id' => null]);

                    return [];
                case 'verify':
                    return $this->verify($file);
                case 'cancel':
                    $this->s3->abort($file);
                    Storage::disk($session->settings['disk'])->delete($file->object_key);
                    $file->update(['status' => 'cancelled', 'multipart_id' => null]);

                    return [];
            }
            abort(404);
        });
    }

    protected function verify(UploadFile $file): array
    {
        $settings = $file->session->settings;
        $disk = Storage::disk($settings['disk']);
        abort_unless($file->size <= $settings['max_bytes'], 422, 'File exceeds the configured size limit.');
        // A previous attempt may have sealed the object and lost its database commit/response.
        // No signing endpoint ever grants access to this destination.
        $source = $file->object_key;
        $sealed = $source.'.sealed';
        if ($disk->exists($source)) {
            abort_unless($disk->size($source) === $file->size, 422, 'Uploaded object size does not match.');
            abort_unless($disk->copy($source, $sealed), 500, 'Unable to seal upload.');
        } else {
            abort_unless($disk->exists($sealed), 422, 'Uploaded object is missing.');
        }
        $file->object_key = $sealed;
        try {
            abort_unless($disk->size($sealed) === $file->size, 422);
            $mime = $this->s3->detectedMime($file);
            $accepted = $settings['types'];
            if ($accepted && ! collect($accepted)->contains(fn ($type) => $mime === $type || (str_ends_with($type, '/*') && str_starts_with($mime, substr($type, 0, -1))))) {
                throw ValidationException::withMessages(['file' => 'The detected file type is not allowed.']);
            }
            if ($validator = config('filament-bulk-upload.validate_upload')) {
                abort_unless($validator($file, $mime), 422, 'Upload content validation failed.');
            }
            $this->s3->setMime($file, $mime);
            $file->update(['mime' => $mime, 'status' => 'uploaded', 'multipart_id' => null]);
        } catch (\Throwable $exception) {
            $disk->delete($sealed);
            throw $exception;
        }
        $disk->delete($source);

        return ['id' => $file->id];
    }

    public function cancel(Request $request, string $session, string $file): array
    {
        return $this->withSession($session, true, function ($session) use ($file) {
            $file = $session->files()->findOrFail($file);
            if ($file->status === 'cancelled') {
                return [];
            }
            $this->s3->abort($file);
            Storage::disk($session->settings['disk'])->delete($file->object_key);
            $file->update(['status' => 'cancelled', 'multipart_id' => null]);

            return [];
        });
    }

    public function media(Request $request, string $session): array
    {
        return $this->withSession($session, false, function ($session) use ($request) {
            if (! isset($session->settings['record'])) {
                return ['data' => [], 'last_page' => 1];
            }
            $page = $request->validate(['page' => 'sometimes|integer|min:1']);
            $record = $this->access->record($session->settings);
            $media = $record->media()->where('collection_name', $session->settings['collection'])->orderBy('order_column')->orderBy('id')->paginate(25, ['*'], 'page', $page['page'] ?? 1);

            return ['data' => $media->map(fn ($item) => [
                'id' => (string) $item->id, 'name' => $item->file_name, 'size' => $item->size,
                'url' => Storage::disk($item->disk)->temporaryUrl($item->getPathRelativeToRoot(), now()->addMinutes(10)),
            ])->all(), 'last_page' => $media->lastPage()];
        });
    }

    public function status(string $session): array
    {
        return $this->withSession($session, false, function ($session) {
            $batch = $session->batch;

            return ['status' => $batch?->status ?? $session->status, 'error' => $batch?->error,
                'counts' => $session->files()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'failed' => $session->files()->where('status', 'failed')->limit(25)->get(['id', 'name', 'error'])];
        });
    }

    public function discard(string $session): array
    {
        return $this->withSession($session, false, function ($session) {
            abort_if($session->settings['readonly'] ?? false, 403);
            $batch = $session->batch;
            abort_unless($batch?->status === 'failed', 409);
            $disk = Storage::disk($session->settings['disk']);
            foreach ($session->files()->where('status', '!=', 'attached')->get() as $file) {
                $this->s3->abort($file);
                $disk->delete($file->object_key);
                $file->update(['status' => 'cancelled', 'multipart_id' => null]);
            }
            $batch->update(['status' => 'discarded']);
            $session->update(['status' => 'expired']);

            return $this->store(new Request(['token' => Crypt::encrypt([
                'owner' => $session->owner, 'tenant' => $session->tenant, 'expires' => time() + 60, 'settings' => $session->settings,
            ])]));
        });
    }

    public function renew(string $session): array
    {
        return $this->withSession($session, false, function ($session) {
            abort_if($session->settings['readonly'] ?? false, 403);
            abort_unless($session->batch?->status === 'completed' || $session->status === 'expired' || ($session->status === 'open' && $session->expires_at->isPast()), 409);

            return $this->store(new Request(['token' => Crypt::encrypt([
                'owner' => $session->owner, 'tenant' => $session->tenant, 'expires' => time() + 60, 'settings' => $session->settings,
            ])]));
        });
    }

    public function retry(string $session): array
    {
        return $this->withSession($session, false, function ($session) {
            abort_if($session->settings['readonly'] ?? false, 403);
            $batch = $session->batch;
            abort_unless($batch && $batch->status === 'failed', 409);
            abort_if($batch->error, 409, 'This batch needs record or collection reconciliation; save a fresh form.');
            $session->files()->where('status', 'failed')->update(['status' => 'uploaded', 'error' => null]);
            $batch->update(['status' => 'pending']);
            ProcessBatch::dispatch($batch->id)->onConnection($session->settings['connection'])->onQueue($session->settings['queue'])->afterCommit();

            return [];
        });
    }

    protected function withSession(string $id, bool $writable, callable $callback): array
    {
        return DB::transaction(function () use ($id, $writable, $callback) {
            $session = UploadSession::query()->lockForUpdate()->findOrFail($id);
            $this->access->session($session, $writable);

            return $callback($session);
        });
    }
}
