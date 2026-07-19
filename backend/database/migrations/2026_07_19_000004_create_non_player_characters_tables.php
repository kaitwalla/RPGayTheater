<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_player_characters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('normal_asset_id')->index();
            $table->string('name', 120);
            $table->string('pronouns', 120)->nullable();
            $table->string('public_description', 500)->nullable();
            $table->enum('native_facing', ['left', 'right'])->default('right');
            $table->timestamps();
        });
        Schema::create('npc_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('npc_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['npc_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_states');
        Schema::dropIfExists('non_player_characters');
    }
};
