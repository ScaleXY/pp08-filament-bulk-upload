<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use ScaleXY\FilamentBulkUpload\Forms\BulkMediaUpload;

class TestActionForm extends TestForm implements HasActions
{
    use InteractsWithActions;

    public function bulkAction(): Action
    {
        return Action::make('bulk')->record($this->record)->schema([BulkMediaUpload::make('media')])->action(fn () => null)->databaseTransaction();
    }
}
