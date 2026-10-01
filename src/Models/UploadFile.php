<?php

namespace ScaleXY\FilamentBulkUpload\Models;

use Illuminate\Database\Eloquent\Model;

class UploadFile extends Model
{
    protected $table = 'bulk_upload_files';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function session()
    {
        return $this->belongsTo(UploadSession::class, 'session_id');
    }
}
