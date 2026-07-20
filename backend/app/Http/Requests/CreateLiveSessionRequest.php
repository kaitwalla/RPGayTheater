<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateLiveSessionRequest extends FormRequest
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
            'campaign_revision_id' => ['required', 'uuid'],
            'progress_mode' => ['required', 'in:fresh,resume'],
            'copy_player_groups' => ['nullable', 'boolean'],
        ];
    }
}
