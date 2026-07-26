<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_cues', function (Blueprint $table): void {
            $table->uuid('concurrent_music_cue_id')->nullable()->index()->after('target_scene_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_cues', fn (Blueprint $table) => $table->dropColumn('concurrent_music_cue_id'));
    }
};
