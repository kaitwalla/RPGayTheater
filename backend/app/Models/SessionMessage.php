<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string $sender_type
 * @property string|null $sender_session_participant_id
 * @property string $target_type
 * @property string|null $target_session_participant_id
 * @property string|null $session_player_group_id
 * @property string|null $reply_to_session_message_id
 * @property string $body
 * @property Carbon $created_at
 */
class SessionMessage extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'sender_type', 'sender_session_participant_id', 'target_type', 'target_session_participant_id', 'session_player_group_id', 'reply_to_session_message_id', 'body'];

    /** @return array{id: string, sender_type: string, sender_session_participant_id: string|null, sender_name: string, target_type: string, target_session_participant_id: string|null, session_player_group_id: string|null, reply_to_session_message_id: string|null, body: string, created_at: string} */
    public function toApi(string $senderName): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender_session_participant_id' => $this->sender_session_participant_id,
            'sender_name' => $senderName,
            'target_type' => $this->target_type,
            'target_session_participant_id' => $this->target_session_participant_id,
            'session_player_group_id' => $this->session_player_group_id,
            'reply_to_session_message_id' => $this->reply_to_session_message_id,
            'body' => $this->body,
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
