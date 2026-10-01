<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ScaleXY\FilamentBulkUpload\Jobs\ProcessBatch;
use ScaleXY\FilamentBulkUpload\Models\UploadFile;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use ScaleXY\FilamentBulkUpload\Support\Access;
use ScaleXY\FilamentBulkUpload\Support\BatchManager;
use ScaleXY\FilamentBulkUpload\Support\UploadDisk;

class MinioTest extends TestCase
{
    public function test_direct_put_multipart_sealing_and_media_copy_against_real_s3(): void
    {
        if (! getenv('MINIO_ENDPOINT')) {
            $this->markTestSkipped('Set MINIO_ENDPOINT to run the real storage integration.');
        }
        $bucket = 'pp08-test-'.bin2hex(random_bytes(6));
        config(['filesystems.disks.s3' => [
            'driver' => 's3', 'key' => getenv('MINIO_KEY') ?: 'pp08-test-key', 'secret' => getenv('MINIO_SECRET') ?: 'pp08-test-secret',
            'region' => 'us-east-1', 'bucket' => $bucket, 'root' => 'scoped', 'endpoint' => getenv('MINIO_ENDPOINT'), 'use_path_style_endpoint' => true, 'throw' => true,
        ]]);
        $disk = Storage::disk('s3');
        $s3 = $disk->getClient();
        $s3->createBucket(['Bucket' => $bucket]);
        try {
            Queue::fake();
            $record = Document::create(['title' => 'Real S3']);
            $access = app(Access::class);
            $settings = ['model' => Document::class, 'record' => (string) $record->id, 'collection' => 'default', 'disk' => 's3', 'max_files' => 1000,
                'max_bytes' => 1_000_000_000, 'types' => ['text/plain'], 'field' => 'data.media', 'queue' => 'bulk-uploads', 'connection' => null, ...app(UploadDisk::class)->snapshot($disk)];
            // The named disk may be stale or belong to another tenant in a worker.
            config(['filesystems.disks.s3.bucket' => 'wrong-tenant']);
            Storage::forgetDisk('s3');
            $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 60, 'settings' => $settings]);
            $sessionId = $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertOk()->json('session');
            $files = $this->postJson("/filament-bulk-upload/{$sessionId}/files", ['files' => [['name' => 'small.txt', 'size' => 5], ['name' => 'large.txt', 'size' => 8 * 1024 * 1024 + 5]]])->assertOk()->json('files');
            $http = new Client;
            $path = "/filament-bulk-upload/{$sessionId}/files/{$files[0]['id']}";
            $signed = $this->postJson("{$path}/put")->assertOk()->json();
            $http->put($signed['url'], ['body' => 'hello', 'headers' => $signed['headers']]);
            $source = UploadFile::find($files[0]['id'])->object_key;
            $this->postJson("{$path}/verify")->assertOk();
            // Reusing a still-valid upload URL cannot replace the sealed original.
            $http->put($signed['url'], ['body' => 'other', 'headers' => $signed['headers']]);
            $this->assertSame('hello', $disk->get(UploadFile::find($files[0]['id'])->object_key));
            $path = "/filament-bulk-upload/{$sessionId}/files/{$files[1]['id']}";
            $this->postJson("{$path}/multipart")->assertOk();
            $parts = [];
            foreach ([str_repeat('a', 8 * 1024 * 1024), 'hello'] as $index => $body) {
                $url = $this->postJson("{$path}/part", ['partNumber' => $index + 1])->assertOk()->json('url');
                $response = $http->put($url, ['body' => $body]);
                $parts[] = ['PartNumber' => $index + 1, 'ETag' => $response->getHeaderLine('ETag')];
            }
            $this->postJson("{$path}/parts")->assertOk()->assertJsonCount(2, 'parts');
            $this->postJson("{$path}/complete", ['parts' => $parts])->assertOk();
            $this->postJson("{$path}/verify")->assertOk();
            $session = UploadSession::find($sessionId);
            $batch = app(BatchManager::class)->submit(['session' => $sessionId, 'order' => array_map(fn ($file) => 'upload:'.$file['id'], $files), 'remove' => [], 'busy' => false], $settings, $record);
            app()->call([new ProcessBatch($batch->id), 'handle']);
            $this->assertSame('completed', $batch->fresh()->status);
            $this->assertSame(2, $record->media()->count());
            foreach ($record->media()->get() as $media) {
                $this->assertTrue($disk->exists($media->getPathRelativeToRoot()));
                $this->assertSame('text/plain', $media->mime_type);
            }
        } finally {
            foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $s3->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                }
            }
            $s3->deleteBucket(['Bucket' => $bucket]);
        }
    }
}
