<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_cues', function (Blueprint $table): void {
            $table->uuid('scene_id')->nullable()->index();
        });
        Schema::table('video_cues', function (Blueprint $table): void {
            $table->uuid('scene_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('video_cues', fn (Blueprint $table) => $table->dropColumn('scene_id'));
        Schema::table('audio_cues', fn (Blueprint $table) => $table->dropColumn('scene_id'));
    }
};
