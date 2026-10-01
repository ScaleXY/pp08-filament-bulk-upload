<?php

use Illuminate\Support\Facades\Route;
use ScaleXY\FilamentBulkUpload\Http\UploadController;

Route::prefix('filament-bulk-upload')->name('bulk-upload.')->middleware(config('filament-bulk-upload.middleware'))->group(function () {
    Route::post('sessions', [UploadController::class, 'store'])->middleware('throttle:60,1')->name('sessions');
    Route::post('{session}/files', [UploadController::class, 'register'])->middleware('throttle:120,1');
    Route::post('{session}/files/{file}/{operation}', [UploadController::class, 'operation'])->whereIn('operation', ['put', 'multipart', 'part', 'parts', 'complete', 'abort', 'verify']);
    Route::post('{session}/files/{file}/cancel', [UploadController::class, 'cancel']);
    Route::post('{session}/media', [UploadController::class, 'media']);
    Route::post('{session}/status', [UploadController::class, 'status']);
    Route::post('{session}/discard', [UploadController::class, 'discard']);
    Route::post('{session}/renew', [UploadController::class, 'renew']);
    Route::post('{session}/retry', [UploadController::class, 'retry']);
});
