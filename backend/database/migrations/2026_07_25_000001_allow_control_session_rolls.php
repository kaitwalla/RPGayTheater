<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_rolls', function (Blueprint $table): void {
            $table->uuid('session_participant_id')->nullable()->change();
            $table->string('roller_name', 120)->nullable()->after('session_participant_id');
        });
    }

    public function down(): void
    {
        Schema::table('session_rolls', function (Blueprint $table): void {
            $table->dropColumn('roller_name');
            $table->uuid('session_participant_id')->nullable(false)->change();
        });
    }
};
