<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_player_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->string('name', 120);
            $table->string('name_normalized', 120);
            $table->timestamps();
            $table->unique(['live_session_id', 'name_normalized']);
        });
        Schema::create('session_player_group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_player_group_id')->index();
            $table->uuid('session_participant_id')->index();
            $table->timestamps();
            $table->unique(['session_player_group_id', 'session_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_player_group_members');
        Schema::dropIfExists('session_player_groups');
    }
};
