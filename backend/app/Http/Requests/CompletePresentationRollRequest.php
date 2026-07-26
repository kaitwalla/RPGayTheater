<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompletePresentationRollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'command_id' => ['required', 'uuid'],
            'overlay_entry_id' => ['required', 'uuid'],
        ];
    }
}
