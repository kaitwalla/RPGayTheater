<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('campaign_revision_id')->index();
            $table->enum('progress_mode', ['fresh', 'resume']);
            $table->string('player_code', 12)->unique();
            $table->char('display_pairing_token_hash', 64)->unique();
            $table->enum('status', ['pending', 'active', 'ended'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_sessions');
    }
};
