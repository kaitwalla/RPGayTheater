<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->json('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_states');
    }
};
