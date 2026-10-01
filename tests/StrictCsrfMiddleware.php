<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class StrictCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
