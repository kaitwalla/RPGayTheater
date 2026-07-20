<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assets', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index()->after('validation_error');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assets', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
