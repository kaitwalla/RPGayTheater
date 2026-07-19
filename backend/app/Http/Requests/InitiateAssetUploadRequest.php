<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateAssetUploadRequest extends FormRequest
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
            'original_filename' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:image,audio,video'],
            'declared_mime' => ['required', 'string', 'max:100'],
            'byte_size' => ['required', 'integer', 'min:1'],
        ];
    }
}
