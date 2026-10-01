<?php

namespace ScaleXY\FilamentBulkUpload\Models;

use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    protected $table = 'bulk_upload_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['settings' => 'array', 'snapshot' => 'array', 'expires_at' => 'datetime'];

    public function files()
    {
        return $this->hasMany(UploadFile::class, 'session_id');
    }

    public function batch()
    {
        return $this->hasOne(UploadBatch::class, 'session_id');
    }
}
