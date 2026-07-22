<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assets', function (Blueprint $table): void {
            $table->string('replacement_original_filename')->nullable();
            $table->string('replacement_declared_mime', 100)->nullable();
            $table->unsignedBigInteger('replacement_byte_size')->nullable();
            $table->string('replacement_upload_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assets', function (Blueprint $table): void {
            $table->dropColumn(['replacement_original_filename', 'replacement_declared_mime', 'replacement_byte_size', 'replacement_upload_id']);
        });
    }
};
