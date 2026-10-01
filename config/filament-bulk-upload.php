<?php

return [
    'disk' => 's3',
    'prefix' => 'filament-bulk-upload/tmp',
    // Set this and the route auth middleware for a non-default panel guard.
    'auth_guard' => null,
    'middleware' => ['web', 'auth'],
    'queue' => 'bulk-uploads',
    'connection' => null,
    'session_hours' => 24,
    'signed_url_minutes' => 10,
    // A persistent, shared cache store with atomic locks is required in production.
    'lock_store' => null,
    // Override for applications whose tenancy is not managed by Filament.
    'tenant_resolver' => null,
    // Optional callable (user, model, operation, tenant): bool. Default: Laravel policies.
    'authorize' => null,
    // Optional callable (upload, detectedMime): bool for deeper content checks.
    'validate_upload' => null,
];
