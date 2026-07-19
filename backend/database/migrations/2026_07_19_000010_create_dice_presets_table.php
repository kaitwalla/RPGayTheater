<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dice_presets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('name', 120);
            $table->string('expression', 200);
            $table->enum('default_visibility', ['public', 'private'])->default('public');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dice_presets');
    }
};
