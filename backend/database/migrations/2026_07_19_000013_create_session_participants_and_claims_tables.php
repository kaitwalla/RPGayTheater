<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->enum('role', ['player', 'spectator']);
            $table->string('display_name', 120);
            $table->string('display_name_normalized', 120);
            $table->char('resume_token_hash', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['live_session_id', 'display_name_normalized']);
        });
        Schema::create('player_character_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->uuid('player_character_id')->index();
            $table->uuid('session_participant_id')->unique();
            $table->timestamps();
            $table->unique(['live_session_id', 'player_character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_character_claims');
        Schema::dropIfExists('session_participants');
    }
};
