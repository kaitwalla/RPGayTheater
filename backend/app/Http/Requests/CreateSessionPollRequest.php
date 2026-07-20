<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSessionPollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['command_id' => ['required', 'uuid'], 'question' => ['required', 'string', 'max:500'], 'options' => ['required', 'array', 'min:2', 'max:12'], 'options.*' => ['required', 'string', 'max:500'], 'allows_multiple' => ['required', 'boolean'], 'target_type' => ['required', 'in:individual,player_group,all_players,all_spectators,all'], 'target_session_participant_id' => ['nullable', 'uuid'], 'session_player_group_id' => ['nullable', 'uuid']];
    }
}
