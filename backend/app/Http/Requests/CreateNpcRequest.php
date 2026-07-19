<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateNpcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'normal_asset_id' => ['required', 'uuid'], 'pronouns' => ['nullable', 'string', 'max:120'], 'public_description' => ['nullable', 'string', 'max:500'], 'native_facing' => ['required', 'in:left,right']];
    }
}
