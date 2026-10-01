<?php

namespace ScaleXY\FilamentBulkUpload\Support;

use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;
use ScaleXY\FilamentBulkUpload\Models\UploadFile;

class S3
{
    protected function location(UploadFile $file): array
    {
        $disk = $file->session->settings['disk'];
        abort_unless(config("filesystems.disks.{$disk}.driver") === 's3', 422, 'An S3 disk is required.');

        return [Storage::disk($disk)->getClient(), [
            'Bucket' => config("filesystems.disks.{$disk}.bucket"),
            'Key' => ltrim(trim(config("filesystems.disks.{$disk}.root", ''), '/').'/'.$file->object_key, '/'),
        ]];
    }

    public function put(UploadFile $file): array
    {
        [$client, $args] = $this->location($file);
        $args['ContentType'] = 'application/octet-stream';
        $args['ContentLength'] = $file->size;
        $request = $client->createPresignedRequest($client->getCommand('PutObject', $args), $this->expiry());

        return ['method' => 'PUT', 'url' => (string) $request->getUri(), 'headers' => ['Content-Type' => 'application/octet-stream']];
    }

    public function createMultipart(UploadFile $file): string
    {
        [$client, $args] = $this->location($file);

        return $client->createMultipartUpload($args + ['ContentType' => 'application/octet-stream'])['UploadId'];
    }

    public function signPart(UploadFile $file, int $part): array
    {
        [$client, $args] = $this->location($file);
        $request = $client->createPresignedRequest($client->getCommand('UploadPart', $args + ['UploadId' => $file->multipart_id, 'PartNumber' => $part]), $this->expiry());

        return ['url' => (string) $request->getUri()];
    }

    public function listParts(UploadFile $file): array
    {
        [$client, $args] = $this->location($file);
        $parts = [];
        foreach ($client->getPaginator('ListParts', $args + ['UploadId' => $file->multipart_id]) as $page) {
            array_push($parts, ...($page['Parts'] ?? []));
        }

        return $parts;
    }

    public function complete(UploadFile $file, array $parts): void
    {
        [$client, $args] = $this->location($file);
        $client->completeMultipartUpload($args + ['UploadId' => $file->multipart_id, 'MultipartUpload' => ['Parts' => $parts]]);
    }

    public function abort(UploadFile $file): void
    {
        if (! $file->multipart_id) {
            return;
        }
        [$client, $args] = $this->location($file);
        try {
            $client->abortMultipartUpload($args + ['UploadId' => $file->multipart_id]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $exception;
            }
        }
    }

    public function setMime(UploadFile $file, string $mime): void
    {
        [$client, $args] = $this->location($file);
        $client->copy($args['Bucket'], $args['Key'], $args['Bucket'], $args['Key'], null,
            ['MetadataDirective' => 'REPLACE', 'ContentType' => $mime]);
    }

    public function detectedMime(UploadFile $file): string
    {
        [$client, $args] = $this->location($file);
        $result = $client->getObject($args + ['Range' => 'bytes=0-65535']);

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $result['Body']) ?: 'application/octet-stream';
    }

    protected function expiry(): string
    {
        return '+'.config('filament-bulk-upload.signed_url_minutes', 10).' minutes';
    }
}
