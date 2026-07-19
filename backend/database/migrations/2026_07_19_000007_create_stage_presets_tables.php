<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_presets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('name', 120);
            $table->unsignedInteger('tween_duration_ms')->default(0);
            $table->enum('tween_easing', ['linear', 'ease_in', 'ease_out', 'ease_in_out'])->default('linear');
            $table->timestamps();
            $table->unique(['campaign_id', 'name']);
        });
        Schema::create('stage_preset_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('stage_preset_id')->index();
            $table->uuid('npc_id')->index();
            $table->uuid('npc_state_id')->nullable()->index();
            $table->decimal('position_x', 5, 4);
            $table->decimal('position_y', 5, 4);
            $table->decimal('scale', 5, 3)->default(1);
            $table->unsignedSmallInteger('layer_order')->default(0);
            $table->enum('facing', ['left', 'right']);
            $table->timestamps();
            $table->unique(['stage_preset_id', 'npc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_preset_entries');
        Schema::dropIfExists('stage_presets');
    }
};
