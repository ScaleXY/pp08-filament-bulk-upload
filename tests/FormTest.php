<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ScaleXY\FilamentBulkUpload\Forms\BulkMediaUpload;

class FormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->addNamespace('filament-bulk-upload', __DIR__.'/views');
    }

    public function test_field_renders_and_defaults_are_configurable(): void
    {
        $component = Livewire::test(TestForm::class)->assertSee('Drop files here');
        $fields = $component->instance()->form->getComponents();
        $this->assertInstanceOf(BulkMediaUpload::class, $fields[0]);
        $this->assertSame(1_000_000_000, $fields[0]->settings()['max_bytes']);
        $this->assertSame(2_097_152, $fields[1]->settings()['max_bytes']);
        $this->assertSame([], $component->instance()->form->getState());
        $this->assertArrayHasKey('media', $component->instance()->data);
    }

    public function test_busy_field_blocks_validation_even_when_not_dehydrated(): void
    {
        $component = Livewire::test(TestForm::class)->set('data.media.busy', true);
        $this->expectException(ValidationException::class);
        $component->instance()->form->getState();
    }
}
