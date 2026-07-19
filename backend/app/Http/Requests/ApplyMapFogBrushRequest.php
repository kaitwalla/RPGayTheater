<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMapFogBrushRequest extends FormRequest
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
            'mode' => ['required', 'in:reveal,hide'],
            'center_x' => ['required', 'numeric', 'between:0,1'],
            'center_y' => ['required', 'numeric', 'between:0,1'],
            'radius' => ['required', 'numeric', 'between:0.005,1'],
        ];
    }
}
