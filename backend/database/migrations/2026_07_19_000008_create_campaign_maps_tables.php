<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_maps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('image_asset_id')->index();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'name']);
        });
        Schema::create('map_fog_masks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('map_id')->unique();
            $table->uuid('asset_id')->index();
            $table->timestamps();
        });
        Schema::create('map_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('map_id')->index();
            $table->enum('token_type', ['pc', 'npc', 'custom']);
            $table->uuid('player_character_id')->nullable()->index();
            $table->uuid('npc_id')->nullable()->index();
            $table->uuid('asset_id')->nullable()->index();
            $table->string('label', 120)->nullable();
            $table->decimal('position_x', 5, 4);
            $table->decimal('position_y', 5, 4);
            $table->decimal('scale', 5, 3)->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_tokens');
        Schema::dropIfExists('map_fog_masks');
        Schema::dropIfExists('campaign_maps');
    }
};
