<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnqueueOverlayRequest extends FormRequest
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
            'placement' => ['required', 'in:corner,full'],
            'content' => ['required', 'string', 'max:4000'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'pinned' => ['required', 'boolean'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'uuid'],
        ];
    }
}
