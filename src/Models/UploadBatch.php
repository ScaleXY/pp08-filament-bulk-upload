<?php

namespace ScaleXY\FilamentBulkUpload\Models;

use Illuminate\Database\Eloquent\Model;

class UploadBatch extends Model
{
    protected $table = 'bulk_upload_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['ordering' => 'array', 'removals' => 'array', 'heartbeat_at' => 'datetime'];

    public function session()
    {
        return $this->belongsTo(UploadSession::class, 'session_id');
    }
}
