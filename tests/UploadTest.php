<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ScaleXY\FilamentBulkUpload\Jobs\ProcessBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadFile;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use ScaleXY\FilamentBulkUpload\Support\Access;
use ScaleXY\FilamentBulkUpload\Support\BatchManager;
use ScaleXY\FilamentBulkUpload\Support\S3;

class UploadTest extends TestCase
{
    protected function settings(?Document $record = null): array
    {
        return array_filter(['model' => Document::class, 'record' => $record?->getKey(), 'collection' => 'default', 'disk' => 's3',
            'max_files' => 1000, 'max_bytes' => 1_000_000_000, 'types' => [], 'field' => 'data.media', 'readonly' => false,
            'queue' => 'bulk-uploads', 'connection' => null], fn ($v, $k) => $k !== 'record' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    protected function createSession(?Document $record = null, array $override = []): UploadSession
    {
        $access = app(Access::class);
        $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => $access->tenant(), 'expires' => time() + 100,
            'settings' => array_replace($this->settings($record), $override)]);
        $id = $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertOk()->json('session');

        return UploadSession::findOrFail($id);
    }

    protected function register(UploadSession $session, int $count = 1): array
    {
        return $this->postJson("/filament-bulk-upload/{$session->id}/files", ['files' => array_map(fn ($i) => ['name' => "file{$i}.txt", 'size' => 5], range(1, $count))])->assertOk()->json('files');
    }

    protected function mockS3(): void
    {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 'local']);
        $this->app->instance(S3::class, new class extends S3
        {
            public function detectedMime(UploadFile $file): string
            {
                return 'text/plain';
            }

            public function setMime(UploadFile $file, string $mime): void {}

            public function abort(UploadFile $file): void {}
        });
    }

    protected function uploaded(UploadSession $session, int $count = 1): array
    {
        $files = $this->register($session, $count);
        foreach ($files as $file) {
            $upload = UploadFile::find($file['id']);
            Storage::disk('s3')->put($upload->object_key, 'hello');
            $this->postJson("/filament-bulk-upload/{$session->id}/files/{$upload->id}/verify")->assertOk();
        }

        return $files;
    }

    protected function state(UploadSession $session, array $files): array
    {
        return ['session' => $session->id, 'order' => array_map(fn ($file) => 'upload:'.$file['id'], $files), 'remove' => [], 'busy' => false];
    }

    public function test_existing_media_grid_pagination_is_bounded_and_includes_image_mime(): void
    {
        $record = Document::create(['title' => 'Grid']);
        $session = $this->createSession($record);
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path, $expires, $options) => 'https://storage.test/'.$path);
        for ($i = 1; $i <= 40; $i++) {
            $record->media()->create(['collection_name' => 'default', 'name' => "Image {$i}", 'file_name' => "image{$i}.png",
                'mime_type' => 'image/png', 'disk' => 's3', 'size' => 5, 'manipulations' => [], 'custom_properties' => [],
                'generated_conversions' => [], 'responsive_images' => [], 'order_column' => $i]);
        }
        foreach ([12, 16, 25, 35] as $size) {
            $this->postJson("/filament-bulk-upload/{$session->id}/media", ['per_page' => $size])->assertOk()
                ->assertJsonCount($size, 'data')->assertJsonPath('last_page', (int) ceil(40 / $size))->assertJsonPath('data.0.mime', 'image/png');
        }
        $this->postJson("/filament-bulk-upload/{$session->id}/media", ['per_page' => 12, 'page' => 4])->assertOk()
            ->assertJsonCount(4, 'data')->assertJsonPath('data.0.name', 'image37.png');
        $this->postJson("/filament-bulk-upload/{$session->id}/media", ['per_page' => 1000])->assertUnprocessable();
    }

    public function test_registers_one_thousand_files_and_rejects_overflow_atomically(): void
    {
        $session = $this->createSession();
        $this->register($session, 1000);
        $this->postJson("/filament-bulk-upload/{$session->id}/files", ['files' => [['name' => 'extra.txt', 'size' => 5]]])->assertUnprocessable();
        $this->assertSame(1000, $session->files()->count());
        $small = $this->createSession(null, ['max_bytes' => 4]);
        $this->postJson("/filament-bulk-upload/{$small->id}/files", ['files' => [['name' => 'x', 'size' => 5]]])->assertUnprocessable();
        $this->assertSame(0, $small->files()->count());
    }

    public function test_enforces_owner_tenant_policy_expiration_and_descriptor_integrity(): void
    {
        $session = $this->createSession();
        $this->postJson('/filament-bulk-upload/sessions', ['token' => 'forged'])->assertForbidden();
        $this->actingAs(User::create(['name' => 'allowed']));
        $this->postJson("/filament-bulk-upload/{$session->id}/status")->assertForbidden();
        $this->actingAs(User::find(1));
        config(['filament-bulk-upload.tenant_resolver' => fn () => 'another-tenant']);
        $this->postJson("/filament-bulk-upload/{$session->id}/status")->assertForbidden();
        config(['filament-bulk-upload.tenant_resolver' => null]);
        $session->update(['expires_at' => now()->subHour()]);
        $this->postJson("/filament-bulk-upload/{$session->id}/files", ['files' => [['name' => 'x', 'size' => 1]]])->assertConflict();
        $this->actingAs(User::create(['name' => 'denied']));
        $access = app(Access::class);
        $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 10, 'settings' => $this->settings()]);
        $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertForbidden();
    }

    public function test_verifies_size_and_content_and_seals_against_overwrite(): void
    {
        $this->mockS3();
        $session = $this->createSession();
        $id = $this->register($session)[0]['id'];
        $file = UploadFile::find($id);
        $source = $file->object_key;
        Storage::disk('s3')->put($source, 'bad');
        $this->postJson("/filament-bulk-upload/{$session->id}/files/{$id}/verify")->assertUnprocessable();
        Storage::disk('s3')->put($source, 'hello');
        $this->postJson("/filament-bulk-upload/{$session->id}/files/{$id}/verify")->assertOk();
        Storage::disk('s3')->put($source, 'other');
        $this->assertSame('hello', Storage::disk('s3')->get($file->fresh()->object_key));
        $this->postJson("/filament-bulk-upload/{$session->id}/files/{$id}/put")->assertConflict();
        $typed = $this->createSession(null, ['types' => ['image/*']]);
        $id = $this->register($typed)[0]['id'];
        Storage::disk('s3')->put(UploadFile::find($id)->object_key, 'hello');
        $this->postJson("/filament-bulk-upload/{$typed->id}/files/{$id}/verify")->assertUnprocessable();
    }

    public function test_rejects_foreign_and_incomplete_references_and_busy_state(): void
    {
        $session = $this->createSession();
        $files = $this->register($session);
        try {
            app(BatchManager::class)->validate($this->state($session, $files), $session->settings);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }
        UploadFile::query()->update(['status' => 'uploaded']);
        $state = $this->state($session, $files);
        $state['order'] = ['upload:forged'];
        $this->expectException(ValidationException::class);
        app(BatchManager::class)->validate($state, $session->settings);
    }

    public function test_attaches_one_thousand_files_in_bounded_jobs_without_duplicates(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Bulk']);
        $session = $this->createSession($record);
        // Registration and verification are covered separately; seed verified objects for this scale test.
        $files = $this->register($session, 1000);
        foreach ($files as $file) {
            $upload = UploadFile::find($file['id']);
            Storage::disk('s3')->put($upload->object_key, 'hello');
            $upload->update(['status' => 'uploaded', 'mime' => 'text/plain']);
        }
        $batch = app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        for ($i = 0; $i < 40; $i++) {
            app()->call([new ProcessBatch($batch->id), 'handle']);
        }
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1000, $record->media()->count());
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame(1000, $record->media()->count());
        $this->assertSame(1000, $session->files()->where('status', 'attached')->count());
    }

    public function test_partial_failure_preserves_success_and_can_retry(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Partial']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session, 2);
        Storage::disk('s3')->delete(UploadFile::find($files[1]['id'])->object_key);
        $batch = app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame(1, $record->media()->count());
        Storage::disk('s3')->put(UploadFile::find($files[1]['id'])->object_key, 'hello');
        $this->postJson("/filament-bulk-upload/{$session->id}/retry")->assertOk();
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(2, $record->media()->count());
    }

    public function test_rollback_does_not_persist_batch_or_dispatch(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Rollback']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session);
        DB::beginTransaction();
        app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        DB::rollBack();
        $this->assertSame(0, UploadBatch::count());
        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_cleanup_protects_submitted_batches_and_recovery_dispatches_them(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Recover']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session);
        $batch = app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        $session->update(['expires_at' => now()->subHour()]);
        $this->artisan('bulk-upload:cleanup')->assertSuccessful();
        $this->assertTrue(Storage::disk('s3')->exists(UploadFile::find($files[0]['id'])->object_key));
        $this->artisan('bulk-upload:recover')->assertSuccessful();
        Queue::assertPushed(ProcessBatch::class);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->artisan('bulk-upload:cleanup')->assertSuccessful();
        $this->assertSame('expired', $session->fresh()->status);
    }

    public function test_create_and_edit_form_relationship_hooks_preserve_processing_state(): void
    {
        $this->mockS3();
        Queue::fake();
        $session = $this->createSession();
        $files = $this->uploaded($session);
        $component = Livewire::test(TestForm::class)->set('data.media', $this->state($session, $files));
        $this->assertSame([], $component->instance()->form->getState());
        $record = Document::create(['title' => 'Created']);
        $component->instance()->form->model($record)->saveRelationships();
        $this->assertSame(1, UploadBatch::count());
        $this->assertSame($session->id, $component->instance()->data['media']['session']);
        app()->call([new ProcessBatch(UploadBatch::first()->id), 'handle']);
        $this->assertSame(1, $record->media()->count());
        $component->instance()->form->getState();
        $this->assertSame(1, UploadBatch::count());
        $editSession = $this->createSession($record);
        $editFiles = $this->uploaded($editSession);
        $state = $this->state($editSession, $editFiles);
        $state['remove'] = [$editSession->snapshot[0]['id']];
        $edit = Livewire::test(TestForm::class, ['record' => $record])->set('data.media', $state);
        $edit->instance()->form->getState();
        $batch = $editSession->fresh()->batch;
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $record->media()->count());
        $this->assertSame($editFiles[0]['id'], $record->media()->first()->getCustomProperty('bulk_upload_id'));
    }

    public function test_crash_after_media_copy_is_reconciled_without_duplicate(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Crash']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session);
        $file = UploadFile::find($files[0]['id']);
        $batch = app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        $record->addMediaFromDisk($file->object_key, 's3')->usingFileName('recovered.txt')->preservingOriginal()
            ->withCustomProperties(['bulk_upload_id' => $file->id, 'bulk_upload_session' => $session->id])->toMediaCollection('default', 's3');
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $record->media()->count());
        $this->assertSame('attached', $file->fresh()->status);
    }

    public function test_verified_selection_can_save_with_stale_busy_flag_but_unregistered_selections_cannot(): void
    {
        $this->mockS3();
        $session = $this->createSession();
        $files = $this->uploaded($session);
        $state = [...$this->state($session, $files), 'busy' => true, 'selected' => 1];
        app(BatchManager::class)->validate($state, $session->settings);
        $this->assertTrue(true);
        $state['selected'] = 2;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Wait for uploads to finish');
        app(BatchManager::class)->validate($state, $session->settings);
    }

    public function test_busy_selection_does_not_allow_unverified_files_even_with_matching_count(): void
    {
        $session = $this->createSession();
        $files = $this->register($session);
        $state = [...$this->state($session, $files), 'busy' => true, 'selected' => 1];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Some files are incomplete or failed.');
        app(BatchManager::class)->validate($state, $session->settings);
    }

    public function test_database_json_key_order_does_not_block_saving_or_removing_media(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'JSON snapshot']);
        foreach (['first.txt', 'second.txt'] as $name) {
            Storage::disk('s3')->put($name, 'hello');
            $record->addMediaFromDisk($name, 's3')->toMediaCollection('default', 's3');
        }
        $session = $this->createSession($record);
        // MySQL JSON stores object keys in its own order, unlike SQLite JSON text.
        $session->update(['snapshot' => array_map(fn ($entry) => ['id' => $entry['id'], 'order' => $entry['order'], 'version' => $entry['version']], $session->snapshot)]);
        $files = $this->uploaded($session);
        $state = $this->state($session, $files);
        $state['remove'] = [$session->snapshot[0]['id']];
        $state['order'] = ['media:'.$session->snapshot[1]['id'], ...$state['order']];
        $batch = app(BatchManager::class)->submit($state, $session->settings, $record);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(2, $record->media()->count());
        $this->assertFalse($record->media()->whereKey($state['remove'][0])->exists());
    }

    public function test_real_media_changes_still_block_saving_after_json_normalization(): void
    {
        $this->mockS3();
        $record = Document::create(['title' => 'Conflict']);
        Storage::disk('s3')->put('existing.txt', 'hello');
        $media = $record->addMediaFromDisk('existing.txt', 's3')->toMediaCollection('default', 's3');
        $session = $this->createSession($record);
        $state = $this->state($session, []);
        $state['order'] = ['media:'.$media->id];
        $media->update(['order_column' => 2]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Media changed in another form.');
        app(BatchManager::class)->validate($state, $session->settings);
    }

    public function test_external_media_change_blocks_batch_and_busy_state_is_rejected(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Concurrent']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session);
        $state = $this->state($session, $files);
        $state['busy'] = true;
        try {
            app(BatchManager::class)->validate($state, $session->settings);
            $this->fail();
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $state['busy'] = false;
        $batch = app(BatchManager::class)->submit($state, $session->settings, $record);
        Storage::disk('s3')->put('other.txt', 'hello');
        $record->addMediaFromDisk('other.txt', 's3')->toMediaCollection('default', 's3');
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertStringContainsString('changed', $batch->fresh()->error);
        $this->assertSame(1, $record->media()->count());
    }

    public function test_modal_action_field_saves_relationships(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Action']);
        $component = Livewire::test(TestActionForm::class, ['record' => $record])->call('mountAction', 'bulk');
        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
        $field = $schema->getComponents()[0];
        $session = $this->createSession($record, ['field' => $field->getStatePath()]);
        $files = $this->uploaded($session);
        $field->state($this->state($session, $files));
        $schema->getState();
        $this->assertNotNull($session->fresh()->batch);
        app()->call([new ProcessBatch($session->fresh()->batch->id), 'handle']);
        $this->assertSame(1, $record->media()->count());
    }

    public function test_reopened_failed_batch_can_retry_or_discard_without_losing_success(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = Document::create(['title' => 'Reopen']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session, 2);
        Storage::disk('s3')->delete(UploadFile::find($files[1]['id'])->object_key);
        $batch = app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame($session->id, $this->createSession($record)->id);
        $result = $this->postJson("/filament-bulk-upload/{$session->id}/discard")->assertOk()->json();
        $this->assertNotSame($session->id, $result['session']);
        $this->assertCount(1, $result['order']);
        $this->assertSame('discarded', $batch->fresh()->status);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame(1, $record->media()->count());
    }

    public function test_rollback_defers_the_real_queue_until_commit(): void
    {
        $this->mockS3();
        $record = Document::create(['title' => 'Real rollback']);
        $session = $this->createSession($record);
        $files = $this->uploaded($session);
        DB::beginTransaction();
        app(BatchManager::class)->submit($this->state($session, $files), $session->settings, $record);
        $this->assertSame(0, $record->media()->count());
        DB::rollBack();
        $this->assertSame(0, UploadBatch::count());
        $this->assertSame(0, $record->media()->count());
    }

    public function test_collection_limits_allow_staged_removal_and_keep_requested_order(): void
    {
        $this->mockS3();
        Queue::fake();
        $record = LimitedDocument::create(['title' => 'Limited']);
        foreach (['one', 'two'] as $name) {
            Storage::disk('s3')->put($name.'.txt', 'hello');
            $record->addMediaFromDisk($name.'.txt', 's3')->toMediaCollection('limited', 's3');
        }
        $session = $this->createSession($record, ['model' => LimitedDocument::class, 'collection' => 'limited']);
        $files = $this->uploaded($session);
        $state = $this->state($session, $files);
        $state['order'][] = 'media:'.$session->snapshot[1]['id'];
        $state['remove'] = [$session->snapshot[0]['id']];
        $batch = app(BatchManager::class)->submit($state, $session->settings, $record);
        app()->call([new ProcessBatch($batch->id), 'handle']);
        $this->assertSame('completed', $batch->fresh()->status);
        $media = $record->media()->orderBy('order_column')->get();
        $this->assertCount(2, $media);
        $this->assertSame($files[0]['id'], $media[0]->getCustomProperty('bulk_upload_id'));
        $this->assertSame($session->snapshot[1]['id'], (string) $media[1]->id);
        $access = app(Access::class);
        $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 60,
            'settings' => array_replace($this->settings($record), ['model' => LimitedDocument::class, 'collection' => 'single'])]);
        $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertUnprocessable();
    }
}
