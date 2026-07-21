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
            $table->string('label', 120)->nullable()->index();
        });
        Schema::table('non_player_characters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        Schema::table('stage_presets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        Schema::create('campaign_asset_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'name']);
        });
        Schema::create('campaign_asset_collection_items', function (Blueprint $table): void {
            $table->uuid('campaign_asset_collection_id');
            $table->uuid('campaign_asset_id');
            $table->timestamps();
            $table->primary(['campaign_asset_collection_id', 'campaign_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_asset_collection_items');
        Schema::dropIfExists('campaign_asset_collections');
        Schema::table('stage_presets', fn (Blueprint $table) => $table->dropColumn('sort_order'));
        Schema::table('non_player_characters', fn (Blueprint $table) => $table->dropColumn('sort_order'));
        Schema::table('campaign_assets', fn (Blueprint $table) => $table->dropColumn('label'));
    }
};
