<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_rolls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('live_session_id')->index();
            $table->uuid('session_participant_id')->index();
            $table->uuid('dice_preset_id')->nullable()->index();
            $table->string('dice_preset_name', 120)->nullable();
            $table->string('expression', 200);
            $table->enum('visibility', ['public', 'private']);
            $table->integer('total');
            $table->json('breakdown');
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();
            $table->index(['live_session_id', 'visibility', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_rolls');
    }
};
