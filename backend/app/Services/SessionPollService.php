<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use App\Models\SessionPoll;
use App\Models\SessionPollOption;
use App\Models\SessionPollRecipient;
use App\Models\SessionPollVote;
use App\Models\SessionPollVoteOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SessionPollService
{
    /**
     * @param  array<int, string>  $options
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function create(string $campaignId, string $sessionId, string $commandId, string $question, array $options, bool $allowsMultiple, string $targetType, ?string $participantId, ?string $groupId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $question, $options, $allowsMultiple, $targetType, $participantId, $groupId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            abort_unless($session->status === 'active', 422, 'Polls can be created only during an active session.');
            $question = trim($question);
            abort_if($question === '', 422, 'A poll question cannot be blank.');
            $options = array_values(array_map(static fn ($option): string => trim((string) $option), $options));
            abort_if(count($options) < 2 || count($options) > 12 || in_array('', $options, true) || count(array_unique(array_map('mb_strtolower', $options))) !== count($options), 422, 'A poll needs 2 to 12 unique non-blank options.');
            [$recipients, $targetParticipantId, $targetGroupId] = $this->recipients($session, $targetType, $participantId, $groupId);
            $poll = SessionPoll::query()->create(['live_session_id' => $session->id, 'question' => $question, 'allows_multiple' => $allowsMultiple, 'target_type' => $targetType, 'target_session_participant_id' => $targetParticipantId, 'session_player_group_id' => $targetGroupId]);
            foreach ($options as $index => $option) {
                SessionPollOption::query()->create(['session_poll_id' => $poll->id, 'body' => $option, 'sort_order' => $index]);
            }
            foreach (array_unique($recipients) as $recipient) {
                SessionPollRecipient::query()->create(['session_poll_id' => $poll->id, 'session_participant_id' => $recipient]);
            }
            $response = ['data' => $this->toApi($poll, null, true)];
            $this->record($session, $poll, $commandId, 'poll.created', 'control', $response);

            return [$response, false];
        });
    }

    /**
     * @param  array<int, string>  $optionIds
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function vote(string $participantId, string $pollId, string $commandId, array $optionIds): array
    {
        return DB::transaction(function () use ($participantId, $pollId, $commandId, $optionIds): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
            /** @var SessionPoll $poll */
            $poll = SessionPoll::query()->where('live_session_id', $participant->live_session_id)->lockForUpdate()->findOrFail($pollId);
            abort_unless($poll->status === 'open', 422, 'This poll is closed.');
            abort_unless(SessionPollRecipient::query()->where('session_poll_id', $poll->id)->where('session_participant_id', $participant->id)->exists(), 403, 'You are not a recipient of this poll.');
            $optionIds = array_values(array_unique(array_filter($optionIds, 'is_string')));
            abort_if(count($optionIds) === 0 || (! $poll->allows_multiple && count($optionIds) !== 1), 422, 'Select a valid number of poll options.');
            $validIds = SessionPollOption::query()->where('session_poll_id', $poll->id)->whereIn('id', $optionIds)->pluck('id')->all();
            abort_unless(count($validIds) === count($optionIds), 422, 'Every selected option must belong to this poll.');
            $vote = SessionPollVote::query()->where('session_poll_id', $poll->id)->where('session_participant_id', $participant->id)->lockForUpdate()->first();
            if ($vote === null) {
                $vote = SessionPollVote::query()->create(['session_poll_id' => $poll->id, 'session_participant_id' => $participant->id]);
            }
            SessionPollVoteOption::query()->where('session_poll_vote_id', $vote->id)->delete();
            foreach ($validIds as $optionId) {
                SessionPollVoteOption::query()->create(['session_poll_vote_id' => $vote->id, 'session_poll_option_id' => $optionId]);
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->findOrFail($poll->live_session_id);
            $response = ['data' => $this->toApi($poll, $participant->id, false)];
            $this->record($session, $poll, $commandId, 'poll.voted', 'participant', $response);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function setState(string $campaignId, string $sessionId, string $pollId, string $commandId, ?string $visibility, bool $close): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $pollId, $commandId, $visibility, $close): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            /** @var SessionPoll $poll */
            $poll = SessionPoll::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($pollId);
            if ($close) {
                $poll->update(['status' => 'closed', 'closed_at' => now()]);
                $eventType = 'poll.closed';
            } else {
                abort_unless(in_array($visibility, ['live', 'final'], true), 422, 'Poll result visibility must be live or final.');
                abort_unless($visibility !== 'final' || $poll->status === 'closed', 422, 'Final results can be published only after closing the poll.');
                $poll->update(['result_visibility' => $visibility]);
                $eventType = 'poll.results_published';
            }
            $poll->refresh();
            $response = ['data' => $this->toApi($poll, null, true)];
            $this->record($session, $poll, $commandId, $eventType, 'control', $response);

            return [$response, false];
        });
    }

    /** @return Collection<int, SessionPoll> */
    public function controlPolls(string $campaignId, string $sessionId): Collection
    {
        LiveSession::query()->where('campaign_id', $campaignId)->findOrFail($sessionId);

        return SessionPoll::query()->where('live_session_id', $sessionId)->orderBy('created_at')->get();
    }

    /** @return Collection<int, SessionPoll> */
    public function participantPolls(string $participantId): Collection
    {
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');

        return SessionPoll::query()->where('live_session_id', $participant->live_session_id)->whereIn('id', SessionPollRecipient::query()->where('session_participant_id', $participant->id)->select('session_poll_id'))->orderBy('created_at')->get();
    }

    /** @return array<string, mixed> */
    public function toApi(SessionPoll $poll, ?string $participantId, bool $includeResults): array
    {
        $options = SessionPollOption::query()->where('session_poll_id', $poll->id)->orderBy('sort_order')->get();
        $myOptionIds = $participantId === null ? [] : SessionPollVoteOption::query()->whereIn('session_poll_vote_id', SessionPollVote::query()->where('session_poll_id', $poll->id)->where('session_participant_id', $participantId)->select('id'))->pluck('session_poll_option_id')->all();
        $counts = $includeResults ? SessionPollVoteOption::query()->whereIn('session_poll_option_id', $options->pluck('id'))->selectRaw('session_poll_option_id, count(*) as total')->groupBy('session_poll_option_id')->pluck('total', 'session_poll_option_id') : collect();

        $closedAt = $poll->closed_at;

        return ['id' => $poll->id, 'question' => $poll->question, 'allows_multiple' => $poll->allows_multiple, 'target_type' => $poll->target_type, 'target_session_participant_id' => $poll->target_session_participant_id, 'session_player_group_id' => $poll->session_player_group_id, 'status' => $poll->status, 'result_visibility' => $poll->result_visibility, 'options' => $options->map(fn (SessionPollOption $option): array => ['id' => $option->id, 'body' => $option->body, 'votes' => $includeResults ? (int) $counts->get($option->id, 0) : null])->all(), 'my_option_ids' => $myOptionIds, 'closed_at' => $closedAt === null ? null : $closedAt->toAtomString(), 'created_at' => $poll->created_at->toAtomString()];
    }

    /** @return array{0: array<int, string>, 1: string|null, 2: string|null} */
    private function recipients(LiveSession $session, string $type, ?string $participantId, ?string $groupId): array
    {
        if ($type === 'individual') {
            $p = SessionParticipant::query()->where('live_session_id', $session->id)->whereNull('revoked_at')->findOrFail($participantId);

            return [[$p->id], $p->id, null];
        }
        if ($type === 'player_group') {
            $g = SessionPlayerGroup::query()->where('live_session_id', $session->id)->findOrFail($groupId);
            $ids = SessionParticipant::query()->where('live_session_id', $session->id)->where('role', 'player')->whereNull('revoked_at')->whereIn('id', SessionPlayerGroupMember::query()->where('session_player_group_id', $g->id)->select('session_participant_id'))->pluck('id')->all();

            return [$ids, null, $g->id];
        }
        abort_if($participantId !== null || $groupId !== null, 422, 'This audience does not accept an individual or group target.');
        $q = SessionParticipant::query()->where('live_session_id', $session->id)->whereNull('revoked_at');
        if ($type === 'all_players') {
            $q->where('role', 'player');
        } elseif ($type === 'all_spectators') {
            $q->where('role', 'spectator');
        }

        return [$q->pluck('id')->all(), null, null];
    }

    /** @param array<string, mixed> $response */
    private function record(LiveSession $session, SessionPoll $poll, string $commandId, string $eventType, string $actor, array $response): void
    {
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_poll', 'aggregate_id' => $poll->id, 'response' => $response]);
        SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => $actor, 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'session_poll_id' => $poll->id], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'session_poll', 'aggregate_id' => $poll->id, 'topic' => 'session_polls.'.$session->id, 'payload' => ['event_type' => $eventType, 'session_poll_id' => $poll->id], 'occurred_at' => now()]);
    }
}
