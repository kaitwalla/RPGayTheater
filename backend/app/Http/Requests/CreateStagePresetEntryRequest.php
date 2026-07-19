<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStagePresetEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'npc_id' => ['required', 'uuid'], 'npc_state_id' => ['nullable', 'uuid'], 'position_x' => ['required', 'numeric', 'between:0,1'], 'position_y' => ['required', 'numeric', 'between:0,1'], 'scale' => ['required', 'numeric', 'between:0.1,5'], 'layer_order' => ['required', 'integer', 'min:0', 'max:65535'], 'facing' => ['required', 'in:left,right']];
    }
}
