<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOverlayEntryRequest extends FormRequest
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
            'placement' => ['sometimes', 'in:corner,full'],
            'content' => ['sometimes', 'string', 'max:4000'],
            'duration_seconds' => ['sometimes', 'integer', 'min:1', 'max:300'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
