<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

class LimitedDocument extends Document
{
    protected $table = 'documents';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('limited')->onlyKeepLatest(2);
        $this->addMediaCollection('single')->singleFile();
    }
}
