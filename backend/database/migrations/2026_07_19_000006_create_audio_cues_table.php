<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_cues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('name', 120);
            $table->enum('kind', ['music', 'sfx']);
            $table->boolean('loop')->default(false);
            $table->unsignedTinyInteger('default_volume')->default(100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_cues');
    }
};
