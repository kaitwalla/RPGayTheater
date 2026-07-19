<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_npc_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->uuid('npc_id')->index();
            $table->enum('author_type', ['participant', 'control']);
            $table->uuid('session_participant_id')->nullable()->index();
            $table->string('body', 2000);
            $table->timestamps();
            $table->index(['live_session_id', 'npc_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_npc_notes');
    }
};
