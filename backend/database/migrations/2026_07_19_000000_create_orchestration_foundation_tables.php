<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->unsignedInteger('draft_revision')->default(1);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('processed_commands', function (Blueprint $table): void {
            $table->uuid('command_id')->primary();
            $table->string('aggregate_type', 80);
            $table->uuid('aggregate_id')->nullable()->index();
            $table->json('response');
            $table->timestamps();
        });

        Schema::create('session_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('actor_type', 40);
            $table->string('event_type', 120);
            $table->uuid('command_id')->nullable()->index();
            $table->json('payload');
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 80);
            $table->uuid('aggregate_id')->nullable()->index();
            $table->string('topic', 120);
            $table->json('payload');
            $table->timestamp('occurred_at')->index();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('session_events');
        Schema::dropIfExists('processed_commands');
        Schema::dropIfExists('campaigns');
    }
};
