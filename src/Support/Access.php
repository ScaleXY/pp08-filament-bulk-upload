<?php

namespace ScaleXY\FilamentBulkUpload\Support;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use ScaleXY\FilamentBulkUpload\Models\UploadSession;
use Spatie\MediaLibrary\HasMedia;

class Access
{
    public function owner(): string
    {
        abort_unless(Auth::user(), 401);

        return Auth::user()->getMorphClass().':'.Auth::id();
    }

    public function tenant(): ?string
    {
        if ($resolver = config('filament-bulk-upload.tenant_resolver')) {
            return ($value = $resolver()) === null ? null : (string) $value;
        }
        if (class_exists(Filament::class) && ($tenant = Filament::getTenant())) {
            return $tenant->getMorphClass().':'.$tenant->getKey();
        }

        return null;
    }

    /** Restore Filament tenancy on package routes from a server-issued descriptor/session only. */
    public function restoreTenant(?string $tenant): void
    {
        if ($tenant === null || config('filament-bulk-upload.tenant_resolver') || $this->tenant() !== null) {
            return;
        }
        abort_unless(class_exists(Filament::class), 403);
        [$type, $id] = explode(':', $tenant, 2);
        $class = Relation::getMorphedModel($type) ?? $type;
        abort_unless(is_subclass_of($class, Model::class), 403);
        $record = $class::query()->findOrFail($id);
        $user = Auth::user();
        abort_unless($user instanceof HasTenants && $user->canAccessTenant($record), 403);
        Filament::setTenant($record);
    }

    public function authorize(array $settings, ?Model $record = null): void
    {
        $record ??= isset($settings['record']) ? $this->record($settings) : null;
        $subject = $record ?? $settings['model'];
        $operation = $record ? (($settings['readonly'] ?? false) ? 'view' : 'update') : 'create';
        if ($callback = config('filament-bulk-upload.authorize')) {
            abort_unless($callback(Auth::user(), $subject, $operation, $this->tenant()), 403);
        } else {
            Gate::authorize($operation, $subject);
        }
    }

    public function record(array $settings): Model&HasMedia
    {
        $class = $settings['model'];
        abort_unless(is_subclass_of($class, Model::class) && is_subclass_of($class, HasMedia::class), 422);

        return $class::query()->findOrFail($settings['record']);
    }

    public function session(UploadSession $session, bool $writable = false): void
    {
        abort_unless($session->owner === $this->owner(), 403);
        $this->restoreTenant($session->tenant);
        abort_unless($session->tenant === $this->tenant(), 403);
        $this->authorize($session->settings);
        if ($writable) {
            abort_if($session->settings['readonly'] ?? false, 403);
            abort_unless($session->status === 'open' && $session->expires_at->isFuture(), 409, 'Upload session expired or already submitted.');
        }
    }
}
