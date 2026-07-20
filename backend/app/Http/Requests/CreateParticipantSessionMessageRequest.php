<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateParticipantSessionMessageRequest extends FormRequest
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
            'target_type' => ['required', 'in:control,player_group'],
            'session_player_group_id' => ['nullable', 'uuid'],
            'reply_to_session_message_id' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
