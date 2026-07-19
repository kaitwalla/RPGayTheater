<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_npc_reveals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->uuid('npc_id')->index();
            $table->boolean('is_revealed')->default(false);
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();
            $table->unique(['live_session_id', 'npc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_npc_reveals');
    }
};
