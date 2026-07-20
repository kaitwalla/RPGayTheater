<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use App\Models\SessionRoll;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SessionRollService
{
    public function __construct(private readonly DiceExpressionEvaluator $evaluator) {}

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $participantId, string $commandId, ?string $expression, ?string $presetId, ?string $visibility): array
    {
        return DB::transaction(function () use ($participantId, $commandId, $expression, $presetId, $visibility): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
            abort_unless($participant->role === 'player', 403, 'Only Players may roll dice.');
            /** @var LiveSession $session */
            $session = LiveSession::query()->lockForUpdate()->findOrFail($participant->live_session_id);
            abort_unless($session->status === 'active', 422, 'Dice can be rolled only during an active session.');
            [$resolvedExpression, $resolvedVisibility, $presetName] = $this->resolve($session, $expression, $presetId, $visibility);
            try {
                $evaluation = $this->evaluator->evaluate($resolvedExpression);
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }
            $roll = SessionRoll::query()->create(['live_session_id' => $session->id, 'session_participant_id' => $participant->id, 'dice_preset_id' => $presetId, 'dice_preset_name' => $presetName, 'expression' => $evaluation['expression'], 'visibility' => $resolvedVisibility, 'total' => $evaluation['total'], 'breakdown' => $evaluation['breakdown']]);
            $response = ['data' => $this->toApi($roll, $participant)];
            $this->record($session, $roll, $commandId, 'roll.created', 'participant', $response);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function reveal(string $campaignId, string $sessionId, string $rollId, string $commandId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $rollId, $commandId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            /** @var SessionRoll $roll */
            $roll = SessionRoll::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($rollId);
            $roll->update(['visibility' => 'public', 'revealed_at' => now()]);
            $roll->refresh();
            $response = ['data' => $this->toApi($roll)];
            $this->record($session, $roll, $commandId, 'roll.revealed', 'control', $response);

            return [$response, false];
        });
    }

    /** @return Collection<int, SessionRoll> */
    public function participantRolls(string $participantId): Collection
    {
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');

        return SessionRoll::query()->where('live_session_id', $participant->live_session_id)->where(fn ($query) => $query->where('visibility', 'public')->orWhere('session_participant_id', $participant->id))->orderBy('created_at')->get();
    }

    /** @return list<array{id: string, name: string, expression: string, default_visibility: string, is_default: bool}> */
    public function participantPresets(string $participantId): array
    {
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $presets = [];
        foreach ($revision->manifest['dice_presets'] ?? [] as $preset) {
            if (! is_array($preset) || ! is_string($preset['id'] ?? null) || ! is_string($preset['name'] ?? null) || ! is_string($preset['expression'] ?? null)) {
                continue;
            }
            $presets[] = ['id' => $preset['id'], 'name' => $preset['name'], 'expression' => $preset['expression'], 'default_visibility' => is_string($preset['default_visibility'] ?? null) ? $preset['default_visibility'] : 'public', 'is_default' => (bool) ($preset['is_default'] ?? false)];
        }

        return $presets;
    }

    /** @return Collection<int, SessionRoll> */
    public function controlRolls(string $campaignId, string $sessionId): Collection
    {
        LiveSession::query()->where('campaign_id', $campaignId)->findOrFail($sessionId);

        return SessionRoll::query()->where('live_session_id', $sessionId)->orderBy('created_at')->get();
    }

    /** @return array<string, mixed> */
    public function toApi(SessionRoll $roll, ?SessionParticipant $participant = null): array
    {
        $roller = $participant ?? SessionParticipant::query()->findOrFail($roll->session_participant_id);
        $revealedAt = $roll->revealed_at;

        return ['id' => $roll->id, 'session_participant_id' => $roll->session_participant_id, 'roller_name' => $roller->display_name, 'dice_preset_id' => $roll->dice_preset_id, 'dice_preset_name' => $roll->dice_preset_name, 'expression' => $roll->expression, 'visibility' => $roll->visibility, 'total' => $roll->total, 'breakdown' => $roll->breakdown, 'revealed_at' => $revealedAt?->toAtomString(), 'created_at' => $roll->created_at->toAtomString()];
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private function resolve(LiveSession $session, ?string $expression, ?string $presetId, ?string $visibility): array
    {
        abort_if(($expression === null || trim($expression) === '') && $presetId === null, 422, 'A dice expression or preset is required.');
        abort_if($expression !== null && trim($expression) !== '' && $presetId !== null, 422, 'Choose either a dice expression or a preset.');
        $preset = null;
        if ($presetId !== null) {
            /** @var CampaignRevision $revision */
            $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
            foreach ($revision->manifest['dice_presets'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['id'] ?? null) === $presetId) {
                    $preset = $candidate;
                    break;
                }
            }
            abort_if($preset === null || ! is_string($preset['expression'] ?? null), 422, 'The selected dice preset is not pinned to this session.');
            $expression = $preset['expression'];
            $visibility ??= is_string($preset['default_visibility'] ?? null) ? $preset['default_visibility'] : 'public';
        }
        $visibility ??= 'public';
        abort_unless(in_array($visibility, ['public', 'private'], true), 422, 'Roll visibility must be public or private.');

        return [(string) $expression, $visibility, is_array($preset) && is_string($preset['name'] ?? null) ? $preset['name'] : null];
    }

    /** @param array<string, mixed> $response */
    private function record(LiveSession $session, SessionRoll $roll, string $commandId, string $eventType, string $actor, array $response): void
    {
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_roll', 'aggregate_id' => $roll->id, 'response' => $response]);
        SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => $actor, 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'session_roll_id' => $roll->id], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'session_roll', 'aggregate_id' => $roll->id, 'topic' => 'session_rolls.'.$session->id, 'payload' => ['event_type' => $eventType, 'session_roll_id' => $roll->id], 'occurred_at' => now()]);
    }
}
