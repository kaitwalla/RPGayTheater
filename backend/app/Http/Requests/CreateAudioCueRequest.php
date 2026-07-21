<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAudioCueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'asset_id' => ['required', 'uuid'], 'scene_id' => ['nullable', 'uuid'], 'kind' => ['required', 'in:music,sfx'], 'loop' => ['boolean'], 'default_volume' => ['integer', 'min:0', 'max:100']];
    }
}
