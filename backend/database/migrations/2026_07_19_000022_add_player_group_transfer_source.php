<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_player_groups', function (Blueprint $table): void {
            $table->uuid('source_session_player_group_id')->nullable()->after('live_session_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('session_player_groups', function (Blueprint $table): void {
            $table->dropIndex(['source_session_player_group_id']);
            $table->dropColumn('source_session_player_group_id');
        });
    }
};
