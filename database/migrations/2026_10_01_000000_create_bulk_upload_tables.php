<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner');
            $table->string('tenant')->nullable();
            $table->json('settings');
            $table->json('snapshot');
            $table->string('status')->default('open')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('bulk_upload_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->unique()->constrained('bulk_upload_sessions');
            $table->string('model_type');
            $table->string('model_id');
            $table->string('collection');
            $table->json('ordering');
            $table->json('removals');
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
        Schema::create('bulk_upload_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('bulk_upload_sessions');
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->string('mime')->nullable();
            $table->string('object_key');
            $table->text('multipart_id')->nullable();
            $table->string('status')->default('registered')->index();
            $table->string('media_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_upload_files');
        Schema::dropIfExists('bulk_upload_batches');
        Schema::dropIfExists('bulk_upload_sessions');
    }
};
