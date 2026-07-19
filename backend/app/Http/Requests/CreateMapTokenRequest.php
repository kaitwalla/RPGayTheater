<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMapTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'token_type' => ['required', 'in:pc,npc,custom'], 'player_character_id' => ['nullable', 'uuid'], 'npc_id' => ['nullable', 'uuid'], 'asset_id' => ['nullable', 'uuid'], 'label' => ['nullable', 'string', 'max:120'], 'position_x' => ['required', 'numeric', 'between:0,1'], 'position_y' => ['required', 'numeric', 'between:0,1'], 'scale' => ['required', 'numeric', 'between:0.1,5']];
    }
}
