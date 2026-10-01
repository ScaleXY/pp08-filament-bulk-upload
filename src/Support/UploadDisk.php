<?php

namespace ScaleXY\FilamentBulkUpload\Support;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class UploadDisk
{
    public function snapshot(AwsS3V3Adapter $disk): array
    {
        $config = $disk->getConfig();
        // Rebuild the adapter in HTTP requests and workers; never serialize clients or closures.
        array_walk_recursive($config, function ($value) {
            if (is_object($value) || is_resource($value)) {
                throw new \InvalidArgumentException('diskInstance requires a serializable Storage::build S3 configuration.');
            }
        });
        $json = json_encode($config, JSON_THROW_ON_ERROR);

        return ['disk_fingerprint' => hash('sha256', $json), 'disk_config' => Crypt::encryptString($json)];
    }

    public function run(array $settings, callable $callback): mixed
    {
        if (! isset($settings['disk_config'])) {
            return $callback();
        }
        $name = $settings['disk'];
        $config = json_decode(Crypt::decryptString($settings['disk_config']), true, flags: JSON_THROW_ON_ERROR);
        $originalConfig = config("filesystems.disks.{$name}");
        $original = $originalConfig ? Storage::disk($name) : null;
        try {
            config(["filesystems.disks.{$name}" => $config]);
            Storage::set($name, Storage::build($config));

            return $callback();
        } finally {
            config(["filesystems.disks.{$name}" => $originalConfig]);
            Storage::forgetDisk($name);
            if ($original !== null) {
                Storage::set($name, $original);
            }
        }
    }
}
