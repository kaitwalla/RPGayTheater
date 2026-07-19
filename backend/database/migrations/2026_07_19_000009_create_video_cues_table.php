<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_cues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('primary_asset_id')->index();
            $table->uuid('fallback_asset_id')->nullable()->index();
            $table->string('name', 120);
            $table->enum('completion_mode', ['restore_captured_scene', 'enter_target_scene']);
            $table->uuid('target_scene_id')->nullable()->index();
            $table->enum('music_during', ['continue', 'pause', 'stop']);
            $table->enum('music_after', ['keep_current', 'resume_prior', 'start_target_default', 'remain_silent']);
            $table->unsignedTinyInteger('embedded_audio_volume')->default(100);
            $table->boolean('embedded_audio_muted')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_cues');
    }
};
