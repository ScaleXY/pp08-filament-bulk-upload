<?php

namespace ScaleXY\FilamentBulkUpload\Support;

use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;

class Collections
{
    public function assertCapacity(HasMedia $record, string $name, int $newCount, int $removed = 0): void
    {
        $record->registerMediaCollections();
        $collection = $record->getRegisteredMediaCollections()->firstWhere('name', $name);
        if ($collection?->singleFile || ($collection?->collectionSizeLimit && $record->media()->where('collection_name', $name)->count() - $removed + $newCount > $collection->collectionSizeLimit)) {
            throw ValidationException::withMessages(['media' => 'This media collection cannot accommodate the upload batch.']);
        }
    }

    /** JSON databases may reorder object keys; preserve row order and normalize each entry. */
    public function normalizeSnapshot(array $snapshot): array
    {
        return array_values(array_map(fn (array $entry) => [
            'id' => (string) $entry['id'],
            'version' => (string) $entry['version'],
            'order' => isset($entry['order']) ? (int) $entry['order'] : null,
        ], $snapshot));
    }

    public function snapshot(HasMedia $record, string $collection): array
    {
        return $record->media()->where('collection_name', $collection)->orderBy('order_column')->orderBy('id')->get(['id', 'updated_at', 'order_column'])
            ->map(fn ($media) => ['id' => (string) $media->id, 'version' => (string) $media->updated_at, 'order' => $media->order_column])->all();
    }
}
