<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudioMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'command_id' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
        if ($this->isMethod('DELETE')) {
            return $rules;
        }
        if ($this->route('record') === null) {
            $rules['ids'] = ['required', 'array', 'min:1'];
        } else {
            $rules['patch'] = ['required', 'array'];
        }

        return $rules + [
            'ids.*' => ['uuid', 'distinct'],
        ];
    }
}
