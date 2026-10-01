<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use ScaleXY\FilamentBulkUpload\Forms\BulkMediaUpload;

// Use as the body of your resource's form() method. Enable panel databaseTransactions().
function documentForm(Schema $schema): Schema
{
    return $schema->components([
        TextInput::make('title')->required(),
        BulkMediaUpload::make('documents')->collection('documents')->disk('s3')
            ->maxFiles(1000)->acceptedFileTypes(['application/pdf', 'image/*'])->queue('bulk-uploads'),
        BulkMediaUpload::make('attachments')->collection('attachments')->disk('s3')->maxFileSize(2 * 1024 * 1024),
    ]);
}
