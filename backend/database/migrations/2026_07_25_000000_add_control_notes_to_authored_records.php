<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_characters', fn (Blueprint $table) => $table->text('control_notes')->nullable()->after('public_description'));
        Schema::table('non_player_characters', fn (Blueprint $table) => $table->text('control_notes')->nullable()->after('public_description'));
        Schema::table('scenes', fn (Blueprint $table) => $table->text('control_notes')->nullable()->after('name'));
    }

    public function down(): void
    {
        Schema::table('scenes', fn (Blueprint $table) => $table->dropColumn('control_notes'));
        Schema::table('non_player_characters', fn (Blueprint $table) => $table->dropColumn('control_notes'));
        Schema::table('player_characters', fn (Blueprint $table) => $table->dropColumn('control_notes'));
    }
};
