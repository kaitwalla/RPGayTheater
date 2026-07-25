<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_presets', function (Blueprint $table): void {
            $table->uuid('scene_backdrop_id')->nullable()->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('stage_presets', function (Blueprint $table): void {
            $table->dropColumn('scene_backdrop_id');
        });
    }
};
