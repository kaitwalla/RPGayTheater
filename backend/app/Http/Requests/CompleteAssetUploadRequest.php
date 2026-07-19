<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAssetUploadRequest extends FormRequest
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
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.number' => ['required', 'integer', 'min:1', 'max:10000', 'distinct'],
            'parts.*.e_tag' => ['required', 'string', 'max:255'],
        ];
    }
}
