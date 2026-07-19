<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('original_filename', 255);
            $table->string('kind', 16);
            $table->string('declared_mime', 100);
            $table->string('validated_mime', 100)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64)->nullable()->index();
            $table->string('storage_key', 512)->nullable()->unique();
            $table->string('upload_id', 512)->nullable()->unique();
            $table->string('upload_status', 16)->index();
            $table->json('metadata')->nullable();
            $table->text('validation_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_assets');
    }
};
