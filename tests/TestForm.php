<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use ScaleXY\FilamentBulkUpload\Forms\BulkMediaUpload;

class TestForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public ?array $data = [];

    public ?Document $record = null;

    public function mount(?Document $record = null): void
    {
        $this->record = $record;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->model($this->record ?? Document::class)->statePath('data')->components([
            BulkMediaUpload::make('media'), BulkMediaUpload::make('attachments')->collection('attachments')->maxFileSize(2048),
        ]);
    }

    public function render()
    {
        return view('filament-bulk-upload::test-form');
    }
}
