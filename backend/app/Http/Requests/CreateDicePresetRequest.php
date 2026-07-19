<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDicePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'expression' => ['required', 'string', 'max:200', 'regex:/^[0-9dDkKhHlL+\-()\s]+$/'], 'default_visibility' => ['required', 'in:public,private'], 'is_default' => ['required', 'boolean']];
    }
}
