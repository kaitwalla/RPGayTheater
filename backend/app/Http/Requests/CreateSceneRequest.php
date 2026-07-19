<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'primary_backdrop_asset_id' => ['nullable', 'uuid'], 'default_music_cue_id' => ['nullable', 'uuid'], 'transition' => ['required', 'in:cut,fade_black,cross_dissolve'], 'transition_duration_ms' => ['required', 'integer', 'min:0', 'max:30000']];
    }
}
