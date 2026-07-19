<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStagePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'tween_duration_ms' => ['required', 'integer', 'min:0', 'max:30000'], 'tween_easing' => ['required', 'in:linear,ease_in,ease_out,ease_in_out']];
    }
}
