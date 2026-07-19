<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->unsignedInteger('number');
            $table->json('manifest');
            $table->char('manifest_hash', 64);
            $table->timestamp('published_at')->index();
            $table->unique(['campaign_id', 'number']);
            $table->unique(['campaign_id', 'manifest_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_revisions');
    }
};
