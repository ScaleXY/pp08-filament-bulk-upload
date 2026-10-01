<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('bulk-media-upload', 'scalexy/filament-bulk-upload') }}"
        x-data="bulkMediaUpload({ state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }}, config: @js($getFrontendConfig()) })"
        class="bulk-upload"
    >
        <div wire:ignore>
            <p x-show="error" x-text="error" role="alert" class="bulk-upload-error"></p>
            <template x-if="!config.readonly && !locked">
                <div>
                    <label class="bulk-upload-drop" :class="{ 'bulk-upload-drag': dragging }"
                        x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="dragging = false; addFiles($event.dataTransfer.files)">
                        <span>Drop files here or choose files</span>
                        <input type="file" multiple :disabled="!ready || registering" x-on:change="addFiles($event.target.files); $event.target.value = ''" aria-label="Choose files" />
                    </label>
                    <p x-text="`Up to ${config.maxFiles} new files, ${formatSize(config.maxBytes)} each`"></p>
                    <button type="button" x-on:click="start()" :disabled="registering || !totalFiles">Upload files</button>
                    <span x-text="`${totalFiles} selected · ${progress}% uploaded`" aria-live="polite"></span>
                    <progress max="100" :value="progress" aria-label="Total upload progress"></progress>
                    <p x-show="state.busy">Upload or remove all selected files before saving.</p>
                </div>
            </template>
            <button type="button" x-show="batch?.status === 'expired' && !config.readonly" x-on:click="newBatch()">Start a fresh upload session</button>
            <template x-if="locked">
                <div role="status">
                    <p x-text="`Media processing: ${batch?.status}`"></p>
                    <p x-text="Object.entries(batch?.counts ?? {}).map(([status, count]) => `${count} ${status}`).join(' · ')"></p>
                    <p x-show="batch?.error" x-text="batch?.error" class="bulk-upload-error"></p>
                    <template x-for="failure in batch?.failed ?? []" :key="failure.id"><p x-text="`${failure.name}: ${failure.error}`"></p></template>
                    <button type="button" x-show="batch?.status === 'failed' && !batch?.error && !config.readonly" x-on:click="retryBatch()">Retry failed attachments</button>
                    <button type="button" x-show="batch?.status === 'failed' && !config.readonly" x-on:click="newBatch('discard')">Discard failed uploads and start another batch</button>
                    <button type="button" x-show="['completed', 'discarded'].includes(batch?.status) && !config.readonly" x-on:click="newBatch()">Start another batch</button>
                </div>
            </template>
            <ul class="bulk-upload-list">
                <template x-for="row in rows" :key="row.id">
                    <li>
                        <span x-text="row.name"></span>
                        <span x-text="`${formatSize(row.size)} · ${row.status} · ${row.progress}%`"></span>
                        <span x-show="row.error" x-text="row.error" class="bulk-upload-error"></span>
                        <div x-show="!locked && !config.readonly">
                            <button type="button" x-show="row.multipart && !row.verified && !row.error" x-on:click="pause(row.id)" x-text="row.paused ? 'Resume' : 'Pause'"></button>
                            <button type="button" x-show="row.error" x-on:click="retry(row.id)">Retry</button>
                            <button type="button" x-on:click="remove(row.id)">Remove</button>
                            <span x-text="`Position ${position(row.reference)}`"></span>
                            <button type="button" x-on:click="move(row.reference, -1)" aria-label="Move upload earlier">↑</button>
                            <button type="button" x-on:click="move(row.reference, 1)" aria-label="Move upload later">↓</button>
                        </div>
                    </li>
                </template>
            </ul>
            <nav x-show="totalPages > 1" aria-label="Upload pages">
                <button type="button" :disabled="page <= 1" x-on:click="changePage(-1)">Previous</button>
                <span x-text="`${page} / ${totalPages}`"></span>
                <button type="button" :disabled="page >= totalPages" x-on:click="changePage(1)">Next</button>
            </nav>
            <p x-show="existing.length">Existing media</p>
            <ul class="bulk-upload-list">
                <template x-for="item in existing" :key="item.id">
                    <li>
                        <a :href="item.url" target="_blank" rel="noopener" x-text="item.name"></a>
                        <span x-text="formatSize(item.size)"></span>
                        <div x-show="!locked && !config.readonly">
                            <button type="button" x-show="!state.remove.includes(item.id)" x-on:click="removeMedia(item.id)">Remove on save</button>
                            <button type="button" x-show="state.remove.includes(item.id)" x-on:click="restoreMedia(item.id)">Undo removal</button>
                            <span x-show="!state.remove.includes(item.id)" x-text="`Position ${position(`media:${item.id}`)}`"></span>
                            <button type="button" x-show="!state.remove.includes(item.id)" x-on:click="move(`media:${item.id}`, -1)" aria-label="Move media earlier">↑</button>
                            <button type="button" x-show="!state.remove.includes(item.id)" x-on:click="move(`media:${item.id}`, 1)" aria-label="Move media later">↓</button>
                        </div>
                    </li>
                </template>
            </ul>
            <nav x-show="mediaLastPage > 1" aria-label="Media pages">
                <button type="button" :disabled="mediaPage <= 1" x-on:click="loadMedia(mediaPage - 1)">Previous</button>
                <span x-text="`${mediaPage} / ${mediaLastPage}`"></span>
                <button type="button" :disabled="mediaPage >= mediaLastPage" x-on:click="loadMedia(mediaPage + 1)">Next</button>
            </nav>
        </div>
    </div>
</x-dynamic-component>
