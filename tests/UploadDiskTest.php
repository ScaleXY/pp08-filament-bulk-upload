<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ScaleXY\FilamentBulkUpload\Support\UploadDisk;

class UploadDiskTest extends TestCase
{
    private function diskConfig(string $bucket): array
    {
        return ['driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'us-east-1', 'bucket' => $bucket,
            'endpoint' => 'http://localhost:9000', 'use_path_style_endpoint' => true, 'root' => 'tenant-root'];
    }

    public function test_field_accepts_a_disk_instance_or_factory_and_encrypts_its_configuration(): void
    {
        $field = Livewire::test(TestForm::class)->instance()->form->getComponents()[0];
        $disk = Storage::build($this->diskConfig('tenant-a'));
        $field->disk('media')->diskInstance(fn () => $disk);
        $first = $field->settings();
        $field->diskInstance($disk);
        $second = $field->settings();
        $this->assertSame($first['disk_fingerprint'], $second['disk_fingerprint']);
        $this->assertStringNotContainsString('test-secret', json_encode($first));
        $this->assertSame('tenant-a', json_decode(Crypt::decryptString($first['disk_config']), true)['bucket']);
        $frontend = $field->getFrontendConfig();
        $this->assertSame('/filament-bulk-upload/sessions', $frontend['endpoint']);
        $this->assertSame(csrf_token(), $frontend['csrfToken']);
    }

    public function test_instance_is_scoped_and_previous_disk_restored_after_exceptions(): void
    {
        config(['filesystems.disks.media' => $this->diskConfig('original')]);
        $original = Storage::disk('media');
        $resolver = app(UploadDisk::class);
        foreach (['tenant-a', 'tenant-b'] as $bucket) {
            $settings = ['disk' => 'media', ...$resolver->snapshot(Storage::build($this->diskConfig($bucket)))];
            try {
                $resolver->run($settings, function () use ($bucket) {
                    $this->assertSame($bucket, Storage::disk('media')->getConfig()['bucket']);
                    $this->assertSame($bucket, config('filesystems.disks.media.bucket'));
                    $this->assertSame('tenant-root/file', Storage::disk('media')->path('file'));
                    throw new \RuntimeException('test');
                });
            } catch (\RuntimeException $exception) {
                $this->assertSame('test', $exception->getMessage());
            }
            $this->assertSame($original, Storage::disk('media'));
            $this->assertSame('original', config('filesystems.disks.media.bucket'));
        }
    }
}
