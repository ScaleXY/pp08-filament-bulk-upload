<?php

namespace ScaleXY\FilamentBulkUpload\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use ScaleXY\FilamentBulkUpload\Support\Access;
use ScaleXY\FilamentBulkUpload\Support\BatchManager;
use ScaleXY\FilamentBulkUpload\Support\UploadDisk;
use Spatie\MediaLibrary\HasMedia;

class BulkMediaUpload extends Field
{
    protected string $view = 'filament-bulk-upload::field';

    protected string|Closure $mediaCollection = 'default';

    protected string|Closure|null $uploadDisk = null;

    protected AwsS3V3Adapter|Closure|null $uploadDiskInstance = null;

    protected int|Closure $fileLimit = 1000;

    protected int|Closure $sizeBytes = 1_000_000_000;

    protected array|Closure $fileTypes = [];

    protected int|Closure $concurrency = 4;

    protected string|Closure|null $queueName = null;

    protected string|Closure|null $queueConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default(['session' => null, 'order' => [], 'remove' => [], 'busy' => false, 'selected' => 0]);
        $this->dehydrated(false);
        $this->rules([fn (BulkMediaUpload $component) => function (string $attribute, mixed $value, Closure $fail) use ($component) {
            if ($component->isDisabled()) {
                return;
            }
            if ($value !== null && ! is_array($value)) {
                $fail('Invalid upload state.');

                return;
            }
            try {
                app(BatchManager::class)->validate($value ?? [], $component->settings());
            } catch (ValidationException $exception) {
                $fail($exception->validator->errors()->first());
            }
        }]);
        $this->saveRelationshipsUsing(function (BulkMediaUpload $component) {
            if ($component->isDisabled()) {
                return;
            }
            app(BatchManager::class)->submit($component->getState() ?? [], $component->settings(), $component->getRecord());
        });
    }

    public function collection(string|Closure $name): static
    {
        $this->mediaCollection = $name;

        return $this;
    }

    public function disk(string|Closure $disk): static
    {
        $this->uploadDisk = $disk;

        return $this;
    }

    /** Keep disk() as the persistent Media Library disk name. */
    public function diskInstance(AwsS3V3Adapter|Closure $disk): static
    {
        $this->uploadDiskInstance = $disk;

        return $this;
    }

    public function maxFiles(int|Closure $count): static
    {
        $this->fileLimit = $count;

        return $this;
    }

    public function maxFileSize(int|Closure $kibibytes): static
    {
        $this->sizeBytes = $kibibytes instanceof Closure ? fn () => $this->evaluate($kibibytes) * 1024 : $kibibytes * 1024;

        return $this;
    }

    public function acceptedFileTypes(array|Closure $types): static
    {
        $this->fileTypes = $types;

        return $this;
    }

    public function uploadConcurrency(int|Closure $count): static
    {
        $this->concurrency = $count;

        return $this;
    }

    public function queue(string|Closure $queue, string|Closure|null $connection = null): static
    {
        $this->queueName = $queue;
        $this->queueConnection = $connection;

        return $this;
    }

    public function settings(): array
    {
        $model = $this->getModel();
        if (! $model || ! is_subclass_of($model, HasMedia::class)) {
            throw new \LogicException('BulkMediaUpload requires an Eloquent model implementing HasMedia.');
        }
        $maxFiles = (int) $this->evaluate($this->fileLimit);
        $maxBytes = (int) $this->evaluate($this->sizeBytes);
        $concurrency = (int) $this->evaluate($this->concurrency);
        if ($maxFiles < 1 || $maxFiles > 1000 || $maxBytes < 1 || $concurrency < 1 || $concurrency > 16) {
            throw new \InvalidArgumentException('Invalid bulk upload limits (1–1000 files, positive size, 1–16 concurrent uploads).');
        }

        $instance = $this->evaluate($this->uploadDiskInstance);
        $diskSettings = $instance === null ? [] : app(UploadDisk::class)->snapshot($instance);

        return array_filter([
            ...$diskSettings,
            'model' => $model, 'record' => $this->getRecord()?->exists ? (string) $this->getRecord()->getKey() : null,
            'collection' => $this->evaluate($this->mediaCollection), 'disk' => $this->evaluate($this->uploadDisk) ?? config('filament-bulk-upload.disk'),
            'max_files' => $maxFiles, 'max_bytes' => $maxBytes, 'types' => $this->evaluate($this->fileTypes), 'concurrency' => $concurrency,
            'field' => $this->getStatePath(), 'readonly' => $this->isDisabled(),
            'queue' => $this->evaluate($this->queueName) ?? config('filament-bulk-upload.queue'),
            'connection' => $this->evaluate($this->queueConnection) ?? config('filament-bulk-upload.connection'),
        ], fn ($value, $key) => $key !== 'record' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function getFrontendConfig(): array
    {
        $access = app(Access::class);
        $settings = $this->settings();

        return ['token' => Crypt::encrypt(['owner' => $access->owner(), 'tenant' => $access->tenant(), 'expires' => time() + 86400, 'settings' => $settings]),
            'endpoint' => route('bulk-upload.sessions', absolute: false), 'csrfToken' => csrf_token(), 'maxFiles' => $settings['max_files'], 'maxBytes' => $settings['max_bytes'],
            'concurrency' => $settings['concurrency'], 'readonly' => $settings['readonly']];
    }
}
