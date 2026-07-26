<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_presets', function (Blueprint $table): void {
            $table->uuid('scene_id')->nullable()->index()->after('campaign_id');
            $table->dropUnique(['campaign_id', 'name']);
            $table->unique(['scene_id', 'name']);
        });

        // A preset could previously be shared by several scenes. Keep the first
        // association on the existing record and make independent copies for the
        // others, so each scene retains an editable, scene-owned preset.
        DB::table('stage_presets')->orderBy('id')->each(function (object $preset): void {
            $sceneIds = DB::table('scenes')
                ->where('base_stage_preset_id', $preset->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($sceneIds === []) {
                return;
            }

            if ($preset->scene_backdrop_id !== null) {
                $backdropSceneId = DB::table('scene_backdrops')
                    ->where('id', $preset->scene_backdrop_id)
                    ->value('scene_id');

                if ($backdropSceneId !== null && in_array($backdropSceneId, $sceneIds, true)) {
                    $sceneIds = array_values(array_unique([$backdropSceneId, ...$sceneIds]));
                }
            }

            DB::table('stage_presets')
                ->where('id', $preset->id)
                ->update(['scene_id' => $sceneIds[0]]);

            $entries = DB::table('stage_preset_entries')
                ->where('stage_preset_id', $preset->id)
                ->orderBy('id')
                ->get();

            foreach (array_slice($sceneIds, 1) as $sceneId) {
                $copyId = (string) Str::uuid7();

                DB::table('stage_presets')->insert([
                    'id' => $copyId,
                    'campaign_id' => $preset->campaign_id,
                    'scene_id' => $sceneId,
                    'name' => $preset->name,
                    // A named backdrop belongs to one scene, so copies start without one.
                    'scene_backdrop_id' => null,
                    'tween_duration_ms' => $preset->tween_duration_ms,
                    'tween_easing' => $preset->tween_easing,
                    'sort_order' => $preset->sort_order,
                    'created_at' => $preset->created_at,
                    'updated_at' => $preset->updated_at,
                ]);

                foreach ($entries as $entry) {
                    DB::table('stage_preset_entries')->insert([
                        'id' => (string) Str::uuid7(),
                        'stage_preset_id' => $copyId,
                        'npc_id' => $entry->npc_id,
                        'npc_state_id' => $entry->npc_state_id,
                        'position_x' => $entry->position_x,
                        'position_y' => $entry->position_y,
                        'scale' => $entry->scale,
                        'layer_order' => $entry->layer_order,
                        'facing' => $entry->facing,
                        'created_at' => $entry->created_at,
                        'updated_at' => $entry->updated_at,
                    ]);
                }

                DB::table('scenes')
                    ->where('id', $sceneId)
                    ->update(['base_stage_preset_id' => $copyId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_presets', function (Blueprint $table): void {
            $table->dropUnique(['scene_id', 'name']);
            $table->dropIndex(['scene_id']);
            $table->dropColumn('scene_id');
            $table->unique(['campaign_id', 'name']);
        });
    }
};
