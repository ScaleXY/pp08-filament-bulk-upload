<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use ScaleXY\FilamentBulkUpload\Models\UploadFile;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use ScaleXY\FilamentBulkUpload\Support\Access;
use ScaleXY\FilamentBulkUpload\Support\S3;

class MultipartTest extends TestCase
{
    public function test_multipart_identity_is_server_controlled_and_parts_can_be_retried(): void
    {
        Storage::fake('s3');
        $this->app->instance(S3::class, new class extends S3
        {
            public array $completed = [];

            public function createMultipart(UploadFile $file): string
            {
                return 'owned-multipart';
            }

            public function signPart(UploadFile $file, int $part): array
            {
                return ['url' => "https://storage.test/{$file->id}/{$file->multipart_id}/{$part}"];
            }

            public function listParts(UploadFile $file): array
            {
                return [['PartNumber' => 1, 'ETag' => 'etag']];
            }

            public function complete(UploadFile $file, array $parts): void
            {
                $this->completed = $parts;
            }

            public function abort(UploadFile $file): void {}
        });
        $access = app(Access::class);
        $settings = ['model' => Document::class, 'disk' => 's3', 'collection' => 'default', 'max_files' => 1000, 'max_bytes' => 1_000_000_000, 'types' => []];
        $id = $this->postJson('/filament-bulk-upload/sessions', ['token' => Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 10, 'settings' => $settings])])->assertOk()->json('session');
        $file = $this->postJson("/filament-bulk-upload/{$id}/files", ['files' => [['name' => 'video.mp4', 'size' => 200_000_000]]])->assertOk()->json('files.0.id');
        $path = "/filament-bulk-upload/{$id}/files/{$file}";
        $this->postJson("{$path}/multipart")->assertOk()->assertJsonPath('uploadId', 'owned-multipart');
        $this->postJson("{$path}/multipart")->assertOk()->assertJsonPath('uploadId', 'owned-multipart');
        $this->postJson("{$path}/part", ['partNumber' => 1, 'key' => 'someone-else', 'uploadId' => 'forged'])->assertOk()->assertJsonPath('url', "https://storage.test/{$file}/owned-multipart/1");
        $this->postJson("{$path}/part", ['partNumber' => 10001])->assertUnprocessable();
        $this->postJson("{$path}/parts")->assertOk()->assertJsonPath('parts.0.ETag', 'etag');
        $this->postJson("{$path}/complete", ['parts' => [['PartNumber' => 2, 'ETag' => 'b'], ['PartNumber' => 1, 'ETag' => 'a']]])->assertOk();
        $this->assertSame(1, app(S3::class)->completed[0]['PartNumber']);
        $this->postJson("{$path}/abort")->assertOk();
        $this->postJson("{$path}/cancel")->assertOk();
        $this->postJson("{$path}/multipart")->assertConflict();
    }

    public function test_real_sdk_signs_scoped_urls_and_retains_disk_root(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'us-east-1', 'bucket' => 'bucket', 'root' => 'tenant-storage', 'endpoint' => 'http://127.0.0.1:9000', 'use_path_style_endpoint' => true]]);
        $session = UploadSession::create(['id' => 'session', 'owner' => app(Access::class)->owner(), 'settings' => ['disk' => 's3'], 'snapshot' => [], 'expires_at' => now()->addDay()]);
        $file = $session->files()->create(['id' => 'file', 'name' => 'x', 'size' => 100, 'object_key' => 'tmp/session/file', 'multipart_id' => 'owned']);
        $put = app(S3::class)->put($file);
        $this->assertStringContainsString('/bucket/tenant-storage/tmp/session/file?', $put['url']);
        $this->assertStringContainsString('X-Amz-Signature=', $put['url']);
        $this->assertStringContainsString('X-Amz-Expires=600', $put['url']);
        $part = app(S3::class)->signPart($file, 2);
        $this->assertStringContainsString('uploadId=owned', $part['url']);
        $this->assertStringContainsString('partNumber=2', $part['url']);
    }
}
