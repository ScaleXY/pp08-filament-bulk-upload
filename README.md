# Filament Bulk Upload

A Filament 4/5 form field for up to **1,000 new uploads per batch**, with direct private S3 uploads, multipart retry, and background Spatie Media Library attachment. Default file limit: **1 GB (1,000,000,000 bytes)**. Existing media can be paginated, removed, and reordered.

## Installation

Requires PHP 8.2+, Laravel 12/13, Filament 4/5, Spatie Media Library v11, an S3-compatible disk, a real queue worker, and a shared cache store supporting atomic locks. Laravel 13 requires PHP 8.3+. Install and migrate Spatie Media Library first.

```sh
composer require scalexy/filament-bulk-upload
php artisan vendor:publish --tag=bulk-upload-config
php artisan vendor:publish --tag=bulk-upload-migrations
php artisan migrate
php artisan filament:assets
```

For local development before publication, add a Composer `path` repository pointing at this directory, then require `scalexy/filament-bulk-upload:@dev`. The package ships compiled assets; consuming applications do not need npm dependencies.

The model must implement `Spatie\MediaLibrary\HasMedia` and use `InteractsWithMedia`. Add a normal multi-file media collection. A `singleFile()` collection is deliberately rejected; count-limited collections must have room for the batch after staged removals.

```php
use ScaleXY\FilamentBulkUpload\Forms\BulkMediaUpload;

BulkMediaUpload::make('media')
    ->collection('documents')
    ->disk('s3')
    ->maxFiles(1000)
    ->maxFileSize(976_562) // Optional override in KiB; omitted default is exactly 1 GB.
    ->acceptedFileTypes(['image/*', 'application/pdf'])
    ->uploadConcurrency(4)
    ->queue('bulk-uploads');
```

Methods accept closures with Filament utility injection. `maxFiles()` accepts 1–1000; `uploadConcurrency()` accepts 1–16. Types are unrestricted when `acceptedFileTypes()` is omitted. Multiple fields must use distinct state paths; prefer distinct collections when saving multiple fields in the same form.

## Saving and background processing

Enable Filament database transactions on the panel:

```php
$panel->databaseTransactions();
```

For standalone actions use `->databaseTransaction()`. For custom Livewire forms wrap record persistence and `saveRelationships()` in `DB::transaction()`. On create, associate the saved model with the schema before saving relationships:

```php
DB::transaction(function () {
    $data = $this->form->getState();
    $record = Document::create($data);
    $this->form->model($record)->saveRelationships();
});
```

The field uses Filament's relationship lifecycle and does not add a `media` attribute to the model's data. Record and package tables must use the default database connection. An upload batch is stored in that transaction and dispatched after commit. Saving finishes before attachments finish. Do not make downstream code assume every media item exists immediately after form save.

Use a background queue such as `database`, Redis, or SQS; **do not use `sync`**. Set `retry_after`/SQS visibility timeout above the 300-second job timeout (e.g. 660 seconds). Jobs process at most 25 files, then dispatch the next slice. Locks last 600 seconds; one record/collection is processed at a time.

```sh
php artisan queue:work --queue=bulk-uploads --timeout=300 --tries=5
```

Run recovery and cleanup through the host scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('bulk-upload:recover')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bulk-upload:cleanup')->hourly()->withoutOverlapping();
```

Configure a persistent shared `lock_store`, such as Redis or the Laravel database cache, across workers. Recovery redispatches pending batches and processing batches with no heartbeat for 15 minutes. Duplicate deliveries are reconciled using stable upload IDs saved in media custom properties. Completed files are kept when another attachment fails; the field exposes processing counts and retry of failed attachments. Check application logs for detailed errors.

Collection changes through other forms produce an explicit conflict. Reload to reconcile them. Reopening the same record field restores its pending/failed batch. Failed batches retain temporary objects for diagnosis/retry; they are not automatically pruned. Use **Discard failed uploads and start another batch** to delete unattached temporary files and begin again, preserving successful media. Administrators must deliberately remove unrecoverable sessions for deleted records and their temporary objects after reconciliation.

## S3 setup

Configure the Laravel disk with `driver => s3`, bucket, region, credentials, and optional endpoint/path-style settings. Both temporary and final originals use the field disk. Keep the bucket private, including the disk's default visibility. Package upload commands do not request public ACLs. Existing media links use short-lived signed URLs.

Temporary uploads are under `filament-bulk-upload/tmp/{session}/{upload}`. Verification copies each object to a sealed sibling key, sets its content type from server detection, and deletes the upload key. No signing operation targets sealed keys. Finalization uses Spatie's `addMediaFromDisk()->preservingOriginal()->toMediaCollection()` and deletes temporary data after successful attachment. On the same disk, Spatie normally performs a storage-side copy. Spatie remote custom headers may force streaming; media conversions can also download originals on queue workers.

Bucket CORS, restricted to your application's origins:

```json
[
  {
    "AllowedOrigins": ["https://admin.example.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

The application's S3 identity needs `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:AbortMultipartUpload`, and `s3:ListMultipartUploadParts` on its temporary and media prefixes. Allow `s3:ListBucket` with matching prefixes. Copying requires read access to the source and write access to the destination. Encrypted buckets also require their relevant KMS permissions. Never expose these credentials in browser configuration.

Add a bucket lifecycle rule to abort incomplete multipart uploads after several days. Do not expire all objects under the temporary prefix after 24 hours: submitted/failed batches may still need them. The package cleanup command expires abandoned sessions after the default 24-hour lifetime and protects unfinished submitted batches. A longer object-expiration rule can be an operator-chosen retention cap, but will make older failed batches unrecoverable.

Spatie has its own size limit. Set `media-library.max_file_size` in **bytes** to at least the largest field limit; otherwise uploads succeed and attachment fails. Configure expensive conversions to run on their own queue.

## Authorization and tenancy

For tenant-specific on-demand S3 adapters, keep a stable disk name for Media Library and supply an adapter or a factory:

```php
BulkMediaUpload::make('media')
    ->disk('tenant_s3')
    ->diskInstance(fn () => Storage::build($tenantS3Configuration));
```

`diskInstance()` accepts Laravel's `AwsS3V3Adapter` or a Filament-evaluated closure returning one. Its configuration must be serializable (standard `Storage::build()` S3 configuration; client objects and credential-provider closures are unsupported). The package encrypts the configuration at rest and rebuilds the adapter for signing, verification, attachment and cleanup. It temporarily installs the adapter under the named disk for Spatie and restores the previous disk afterwards, including on failure. Keep the application encryption key available to queue workers. Explicit temporary credentials must remain valid for the lifetime of the batch. The host must still resolve the named disk for normal media access and separately queued conversions.

The field detects the current Filament panel's authentication guard. Package routes also need the matching guard and tenancy middleware. For example, with a `tenant_admin` guard and Stancl domain tenancy:

```php
// config/filament-bulk-upload.php
'auth_guard' => 'tenant_admin',
'connection' => 'tenant_connection', // The host's tenant-aware queue connection.
'middleware' => [
    'web',
    \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
    \Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains::class,
    'auth:tenant_admin',
],
'tenant_resolver' => [\App\Providers\TenancyServiceProvider::class, 'bulkUploadTenantKey'],
```

Stancl tenant uploads must dispatch through a tenant-aware queue connection. A connection configured with `central => true` omits the tenant ID, so the worker cannot find tenant database batches. Set `connection` as above, or use `->queue('bulk-uploads', 'tenant_connection')`, and configure workers for that connection. For a batch previously queued on the wrong connection, initialize its tenant, update its persisted session `settings.connection`, then redispatch `ProcessBatch` under that tenant context. Restart workers after changing application configuration.

Define the static resolver on the application's tenancy provider:

```php
public static function bulkUploadTenantKey(): ?string
{
    $key = tenant()?->getTenantKey();

    return $key === null ? null : (string) $key;
}
```

Use static method callables such as `[Resolver::class, 'resolve']` in configuration files. Laravel's `config:cache` cannot serialize closures. The resolver is called for each request, so the cached configuration does not capture a particular tenant. This also applies to custom `authorize` and `validate_upload` callbacks.

Tenancy must initialize before session and CSRF middleware through the host's middleware priority configuration. Upload requests use a relative endpoint and the page's CSRF token, including Filament/Livewire pages without a CSRF meta tag. After updating the package, run `php artisan filament:assets` to publish the updated JavaScript.

Routes use `web` and `auth` middleware, including Laravel CSRF protection. Laravel `create`/`update` policies are required by default. Replace the configured middleware for a custom guard and add any application-specific account checks. Configure `filament-bulk-upload.authorize` only when custom policy behavior is needed; its signature is `(user, modelOrClass, operation, tenant): bool`.

Session settings are encrypted server-issued descriptors, bound to user, tenant, and field. The browser cannot choose storage keys, disks, collections, model classes, multipart identities, or attachment targets. Ownership and policy checks run on every endpoint and again at form save.

For Filament tenancy, package routes restore the tenant from the trusted descriptor/session and require `HasTenants::canAccessTenant()`. If tenancy is already established by middleware, it must match. For custom tenancy set `tenant_resolver` to a callable returning a stable tenant identifier, and apply the middleware needed to establish that context on both form and upload routes. Cross-tenant session reuse is rejected.

Content rules use server-side MIME detection on a bounded 64 KiB sample, not the submitted MIME header. This is file-type validation, not an antivirus scanner or full document parser. Add `validate_upload` (callable `(UploadFile $file, string $detectedMime): bool`) for deeper validation. Office formats identified as ZIP may need a custom content validator. Original names are sanitized by Spatie.

## Browser behavior

Choose or drop files, then click **Upload files**. Multipart uploads begin at 100 MiB; concurrency defaults to four. Retry failed uploads or remove them before saving. A server check also rejects incomplete references even if browser state is forged.

Only 25 rows per list render at a time. File bytes and progress stay outside Livewire; form state contains the session, ordered references, staged removal IDs, and a busy flag. Existing media removal/reordering applies after save. Uploaded files can be positioned alongside existing media. Saving other form fields while the same batch is processing does not submit another batch.

Pause/resume and retries work within the open page. Reopening or refreshing requires selecting unfinished files again. Abandoned uploads expire. After a completed batch, **Start another batch** creates a fresh session against the saved record.

## Development and verification

```sh
composer install
npm ci
npm run build
composer test
npm test
npx playwright install chromium
npx playwright test
```

The suite covers 1,000-file registration/attachment, bounded jobs, duplicate and interrupted jobs, partial failure/retry, rollback, recovery, cleanup, descriptor tampering, ownership/tenancy, MIME and size validation, actual SDK signing, Filament create/edit/action lifecycle, and a 1,000-file browser upload through stubbed HTTP boundaries. CI runs Filament 4/5 and Laravel 12/13 combinations.

Run the real S3 test with a local MinIO service. It creates and removes a disposable bucket:

```sh
MINIO_ROOT_USER=pp08-test-key MINIO_ROOT_PASSWORD=pp08-test-secret \
  minio server /tmp/pp08-minio-data --address 127.0.0.1:19000
MINIO_ENDPOINT=http://127.0.0.1:19000 vendor/bin/phpunit --filter MinioTest
```

Custom credentials: `MINIO_KEY` and `MINIO_SECRET`. The test exercises signed PUT, multipart parts/list/completion, immutable verification, and real Media Library attachment on the same S3 disk.

For an AWS smoke test in a staging host, configure a disposable private bucket and the CORS/IAM above; upload one small file and a file over 100 MiB through the field, pause/resume the multipart file, save, run the worker, and verify signed previews and temporary cleanup. Repeat with a rejected type and a size above the field limit. AWS verification needs your account and staging configuration and is not exercised by the local test suite.

Selected uploads and existing media display in paginated image grids. The bottom **Grid layout** selector defaults to **5 × 5** (25 per page), with **4 × 3** (12), **7 × 5** (35), and **4 × 4** (16) options. Columns adapt on narrow screens while keeping the selected page size. Local previews are limited to the visible page and their object URLs are released on page changes and field teardown. Existing media previews use signed URLs; other file types show a file placeholder.
