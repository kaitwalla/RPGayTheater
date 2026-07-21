<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVideoCueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'primary_asset_id' => ['required', 'uuid'], 'scene_id' => ['nullable', 'uuid'], 'fallback_asset_id' => ['nullable', 'uuid'], 'completion_mode' => ['required', 'in:restore_captured_scene,enter_target_scene'], 'target_scene_id' => ['nullable', 'uuid'], 'music_during' => ['required', 'in:continue,pause,stop'], 'music_after' => ['required', 'in:keep_current,resume_prior,start_target_default,remain_silent'], 'embedded_audio_volume' => ['required', 'integer', 'min:0', 'max:100'], 'embedded_audio_muted' => ['required', 'boolean']];
    }
}
