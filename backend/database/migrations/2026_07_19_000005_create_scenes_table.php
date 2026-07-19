<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('name', 120);
            $table->uuid('primary_backdrop_asset_id')->nullable()->index();
            $table->uuid('default_music_cue_id')->nullable()->index();
            $table->uuid('base_stage_preset_id')->nullable()->index();
            $table->enum('transition', ['cut', 'fade_black', 'cross_dissolve'])->default('cut');
            $table->unsignedInteger('transition_duration_ms')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('scene_backdrops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('scene_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['scene_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_backdrops');
        Schema::dropIfExists('scenes');
    }
};
