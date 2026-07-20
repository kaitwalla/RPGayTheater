<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->enum('sender_type', ['control', 'participant']);
            $table->uuid('sender_session_participant_id')->nullable()->index();
            $table->enum('target_type', ['control', 'individual', 'player_group', 'all_players', 'all_spectators', 'all']);
            $table->uuid('target_session_participant_id')->nullable()->index();
            $table->uuid('session_player_group_id')->nullable()->index();
            $table->uuid('reply_to_session_message_id')->nullable()->index();
            $table->string('body', 2000);
            $table->timestamps();
            $table->index(['live_session_id', 'created_at']);
        });
        Schema::create('session_message_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_message_id')->index();
            $table->uuid('session_participant_id')->index();
            $table->timestamps();
            $table->unique(['session_message_id', 'session_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_message_recipients');
        Schema::dropIfExists('session_messages');
    }
};
