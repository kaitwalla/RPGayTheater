<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_polls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->string('question', 500);
            $table->boolean('allows_multiple');
            $table->enum('target_type', ['individual', 'player_group', 'all_players', 'all_spectators', 'all']);
            $table->uuid('target_session_participant_id')->nullable()->index();
            $table->uuid('session_player_group_id')->nullable()->index();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->enum('result_visibility', ['none', 'live', 'final'])->default('none');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['live_session_id', 'created_at']);
        });
        Schema::create('session_poll_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_poll_id')->index();
            $table->string('body', 500);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['session_poll_id', 'sort_order']);
        });
        Schema::create('session_poll_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_poll_id')->index();
            $table->uuid('session_participant_id')->index();
            $table->timestamps();
            $table->unique(['session_poll_id', 'session_participant_id']);
        });
        Schema::create('session_poll_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_poll_id')->index();
            $table->uuid('session_participant_id')->index();
            $table->timestamps();
            $table->unique(['session_poll_id', 'session_participant_id']);
        });
        Schema::create('session_poll_vote_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_poll_vote_id')->index();
            $table->uuid('session_poll_option_id')->index();
            $table->timestamps();
            $table->unique(['session_poll_vote_id', 'session_poll_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_poll_vote_options');
        Schema::dropIfExists('session_poll_votes');
        Schema::dropIfExists('session_poll_recipients');
        Schema::dropIfExists('session_poll_options');
        Schema::dropIfExists('session_polls');
    }
};
