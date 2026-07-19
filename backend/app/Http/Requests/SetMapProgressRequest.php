<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetMapProgressRequest extends FormRequest
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
            'tokens' => ['required', 'array'],
            'tokens.*.source_token_id' => ['required', 'uuid'],
            'tokens.*.position_x' => ['required', 'numeric', 'between:0,1'],
            'tokens.*.position_y' => ['required', 'numeric', 'between:0,1'],
            'tokens.*.scale' => ['required', 'numeric', 'between:0.1,5'],
            'tokens.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
