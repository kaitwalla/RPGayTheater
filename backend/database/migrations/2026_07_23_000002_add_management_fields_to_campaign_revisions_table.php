<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->string('name', 120)->default('Published revision')->after('number');
            $table->timestampTz('archived_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->dropColumn(['name', 'archived_at']);
        });
    }
};
