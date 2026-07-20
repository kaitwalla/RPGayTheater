<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSessionRollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'expression' => ['nullable', 'string', 'max:200'], 'dice_preset_id' => ['nullable', 'uuid'], 'visibility' => ['nullable', 'in:public,private']];
    }
}
