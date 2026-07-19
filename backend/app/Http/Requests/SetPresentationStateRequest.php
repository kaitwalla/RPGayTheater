<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPresentationStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'command_id' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'array'],
            'state.scene_id' => ['nullable', 'uuid'],
            'state.backdrop_asset_id' => ['nullable', 'uuid'],
            'state.music_cue_id' => ['nullable', 'uuid'],
            'state.music_playback' => ['nullable', 'array'],
            'state.music_playback.status' => ['nullable', 'in:playing,paused,stopped'],
            'state.music_playback.position_seconds' => ['nullable', 'numeric', 'min:0'],
            'state.music_playback.position_command_id' => ['nullable', 'uuid'],
            'state.music_playback.loop' => ['nullable', 'boolean'],
            'state.music_playback.volume' => ['nullable', 'numeric', 'between:0,1'],
            'state.music_playback.fade_duration_ms' => ['nullable', 'integer', 'min:0', 'max:30000'],
            'state.sfx_master_volume' => ['nullable', 'numeric', 'between:0,1'],
            'state.sfx_instances' => ['nullable', 'array', 'max:64'],
            'state.sfx_instances.*.id' => ['required_with:state.sfx_instances', 'uuid'],
            'state.sfx_instances.*.cue_id' => ['required_with:state.sfx_instances', 'uuid'],
            'state.sfx_instances.*.loop' => ['required_with:state.sfx_instances', 'boolean'],
            'state.sfx_instances.*.volume' => ['required_with:state.sfx_instances', 'numeric', 'between:0,1'],
            'state.video_cue_id' => ['nullable', 'uuid'],
            'state.stage_preset_id' => ['nullable', 'uuid'],
            'state.stage_entries' => ['required', 'array'],
            'state.stage_entries.*.npc_id' => ['required', 'uuid'],
            'state.stage_entries.*.npc_state_id' => ['nullable', 'uuid'],
            'state.stage_entries.*.position_x' => ['required', 'numeric', 'between:0,1'],
            'state.stage_entries.*.position_y' => ['required', 'numeric', 'between:0,1'],
            'state.stage_entries.*.scale' => ['required', 'numeric', 'between:0.1,5'],
            'state.stage_entries.*.layer_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'state.stage_entries.*.facing' => ['required', 'in:left,right'],
        ];
    }
}
