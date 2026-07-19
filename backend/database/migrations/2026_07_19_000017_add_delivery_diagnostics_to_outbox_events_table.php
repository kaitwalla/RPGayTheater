<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->timestamp('last_attempted_at')->nullable()->index()->after('attempts');
            $table->timestamp('dispatching_at')->nullable()->index()->after('last_attempted_at');
            $table->string('last_error', 1000)->nullable()->after('dispatching_at');
            $table->index(['dispatched_at', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex(['dispatched_at', 'occurred_at']);
            $table->dropColumn(['last_attempted_at', 'dispatching_at', 'last_error']);
        });
    }
};
