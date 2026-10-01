<?php

namespace ScaleXY\FilamentBulkUpload\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ScaleXY\FilamentBulkUpload\Jobs\ProcessBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use Spatie\MediaLibrary\HasMedia;

class BatchManager
{
    public function __construct(protected Access $access, protected Collections $collections) {}

    public function validate(array $state, array $settings): void
    {
        if ($state['busy'] ?? false) {
            $this->invalid('Wait for uploads to finish, or remove failed files.');
        }
        if (! ($state['session'] ?? null)) {
            if (($state['order'] ?? []) || ($state['remove'] ?? [])) {
                $this->invalid('Missing upload session.');
            }

            return;
        }
        if (! is_string($state['session']) || ! Str::isUuid($state['session'])) {
            $this->invalid('Invalid upload session.');
        }
        $session = UploadSession::findOrFail($state['session']);
        $this->access->session($session);
        foreach (['model', 'collection', 'disk', 'max_files', 'max_bytes', 'types', 'field', 'queue', 'connection'] as $key) {
            if (($session->settings[$key] ?? null) !== ($settings[$key] ?? null)) {
                $this->invalid('Upload field configuration changed; start a new batch.');
            }
        }
        if ($session->status === 'submitted' && ($batch = $session->batch)) {
            if ($batch->model_type !== $settings['model'] || $batch->model_id !== (string) ($settings['record'] ?? '') || $batch->ordering !== ($state['order'] ?? []) || $batch->removals !== ($state['remove'] ?? [])) {
                $this->invalid('This batch has already been submitted.');
            }

            return;
        }
        $this->access->session($session, true);
        if (isset($session->settings['record']) && (string) $session->settings['record'] !== (string) ($settings['record'] ?? '')) {
            $this->invalid('Wrong upload target.');
        }
        $order = $state['order'] ?? [];
        if (! is_array($order) || ! is_array($state['remove'] ?? []) || count(array_filter($order, 'is_string')) !== count($order) || count(array_filter($state['remove'] ?? [], fn ($value) => is_string($value) || is_int($value))) !== count($state['remove'] ?? [])) {
            $this->invalid('Invalid media state.');
        }
        $removals = array_map('strval', $state['remove'] ?? []);
        if (! is_array($order) || count($order) !== count(array_unique($order)) || count($removals) !== count(array_unique($removals))) {
            $this->invalid('Invalid media ordering.');
        }
        $existing = array_column($session->snapshot, 'id');
        if (array_diff($removals, $existing)) {
            $this->invalid('Cannot remove media outside this collection.');
        }
        $files = $session->files()->where('status', '!=', 'cancelled')->get();
        if ($files->count() > $settings['max_files'] || $files->contains(fn ($file) => $file->status !== 'uploaded')) {
            $this->invalid('Some files are incomplete or failed.');
        }
        $expected = array_merge(array_map(fn ($id) => 'media:'.$id, array_diff($existing, $removals)), $files->map(fn ($file) => 'upload:'.$file->id)->all());
        sort($expected);
        $actual = $order;
        sort($actual);
        if ($actual !== $expected) {
            $this->invalid('Invalid upload references or incomplete media ordering.');
        }
        if (isset($settings['record'])) {
            $record = $this->access->record($settings);
            if ($this->collections->snapshot($record, $settings['collection']) !== $session->snapshot) {
                $this->invalid('Media changed in another form. Reload before saving.');
            }
            $this->collections->assertCapacity($record, $settings['collection'], $files->count(), count($removals));
        }
    }

    public function submit(array $state, array $settings, Model&HasMedia $record): ?UploadBatch
    {
        if (! ($state['session'] ?? null)) {
            return null;
        }
        // The host form must use the same database connection and enable database transactions.
        if ($record->getConnection()->getName() !== DB::connection()->getName()) {
            $this->invalid('Bulk uploads require the record and package tables on the default database connection.');
        }

        return DB::transaction(function () use ($state, $settings, $record) {
            $session = UploadSession::query()->lockForUpdate()->findOrFail($state['session']);
            if ($batch = $session->batch) {
                $this->access->session($session);
                if ($batch->model_type !== $record::class || $batch->model_id !== (string) $record->getKey() || $batch->ordering !== $state['order'] || $batch->removals !== ($state['remove'] ?? [])) {
                    $this->invalid('This batch has already been submitted.');
                }

                return $batch;
            }
            $this->validate($state, $settings);
            $this->access->authorize($session->settings, isset($session->settings['record']) ? $record : null);
            $this->collections->assertCapacity($record, $settings['collection'], $session->files()->where('status', 'uploaded')->count(), count($state['remove'] ?? []));
            $batch = UploadBatch::create([
                'id' => (string) Str::uuid(), 'session_id' => $session->id, 'model_type' => $record::class,
                'model_id' => (string) $record->getKey(), 'collection' => $settings['collection'],
                'ordering' => $state['order'], 'removals' => $state['remove'] ?? [],
            ]);
            $session->update(['status' => 'submitted', 'settings' => array_merge($session->settings, ['record' => (string) $record->getKey()])]);
            ProcessBatch::dispatch($batch->id)->onConnection($settings['connection'])->onQueue($settings['queue'])->afterCommit();

            return $batch;
        });
    }

    protected function invalid(string $message): never
    {
        throw ValidationException::withMessages(['media' => $message]);
    }
}
