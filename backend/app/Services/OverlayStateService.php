<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleOverlayState;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\OverlayState;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use App\Models\SessionRoll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OverlayStateService
{
    /** @var list<string> */
    private const LANES = ['corner', 'full'];

    /** @return array<string, mixed> */
    public static function initialState(): array
    {
        return ['corner' => ['current' => null, 'queue' => []], 'full' => ['current' => null, 'queue' => []]];
    }

    public function snapshot(LiveSession $session): OverlayState
    {
        return OverlayState::query()->firstOrCreate(['live_session_id' => $session->id], ['revision' => 1, 'state' => self::initialState()]);
    }

    public function enqueuePublicRoll(LiveSession $session, SessionRoll $roll, SessionParticipant $participant, string $commandId): void
    {
        $snapshot = OverlayState::query()->where('live_session_id', $session->id)->lockForUpdate()->first();
        if ($snapshot === null) {
            $snapshot = OverlayState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'state' => self::initialState()]);
        }
        $state = $this->normalize($snapshot->state);
        $entry = $this->entry(['content' => $participant->display_name.' rolled '.$roll->total.'.', 'duration_seconds' => 8, 'pinned' => false, 'source_type' => 'session_roll', 'source_id' => $roll->id]);
        if ($state['corner']['current'] === null) {
            $state['corner']['current'] = $entry;
        } else {
            $state['corner']['queue'][] = $entry;
        }
        $snapshot->update(['state' => $state, 'revision' => $snapshot->revision + 1]);
        SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => 'participant', 'event_type' => 'overlay_state.roll_enqueued', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'overlay_state_id' => $snapshot->id, 'session_roll_id' => $roll->id, 'revision' => $snapshot->revision + 1], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'overlay_state', 'aggregate_id' => $snapshot->id, 'topic' => 'overlay_states.'.$session->id, 'payload' => ['event_type' => 'overlay_state.roll_enqueued', 'revision' => $snapshot->revision + 1], 'occurred_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function enqueue(string $campaignId, string $sessionId, string $commandId, int $expectedRevision, array $entry): array
    {
        $placement = $entry['placement'];
        if (! is_string($placement)) {
            abort(422, 'An overlay placement is required.');
        }
        $this->assertLane($placement);

        return $this->mutate($campaignId, $sessionId, $commandId, $expectedRevision, 'overlay_state.enqueued', function (array $state) use ($entry, $placement): array {
            $overlay = $this->entry($entry);
            if ($state[$placement]['current'] === null) {
                $state[$placement]['current'] = $overlay;
            } else {
                $state[$placement]['queue'][] = $overlay;
            }

            return $state;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function update(string $campaignId, string $sessionId, string $entryId, string $commandId, int $expectedRevision, array $patch): array
    {
        return $this->mutate($campaignId, $sessionId, $commandId, $expectedRevision, 'overlay_state.updated', function (array $state) use ($entryId, $patch): array {
            foreach (self::LANES as $lane) {
                $current = $state[$lane]['current'];
                if (is_array($current) && ($current['id'] ?? null) === $entryId) {
                    return $this->updateAt($state, $lane, 'current', null, $patch);
                }
                foreach ($state[$lane]['queue'] as $index => $queued) {
                    if (is_array($queued) && ($queued['id'] ?? null) === $entryId) {
                        return $this->updateAt($state, $lane, 'queue', $index, $patch);
                    }
                }
            }

            abort(404, 'The overlay entry does not belong to this session.');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function advance(string $campaignId, string $sessionId, string $lane, string $commandId, int $expectedRevision): array
    {
        return $this->removeCurrent($campaignId, $sessionId, $lane, $commandId, $expectedRevision, 'overlay_state.advanced');
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function dismiss(string $campaignId, string $sessionId, string $lane, string $commandId, int $expectedRevision): array
    {
        return $this->removeCurrent($campaignId, $sessionId, $lane, $commandId, $expectedRevision, 'overlay_state.dismissed');
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function removeCurrent(string $campaignId, string $sessionId, string $lane, string $commandId, int $expectedRevision, string $eventType): array
    {
        $this->assertLane($lane);

        return $this->mutate($campaignId, $sessionId, $commandId, $expectedRevision, $eventType, function (array $state) use ($lane): array {
            abort_if($state[$lane]['current'] === null, 422, 'This overlay lane has no active item.');
            $state[$lane]['current'] = null;
            $this->promote($state, $lane);

            return $state;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $operation
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function mutate(string $campaignId, string $sessionId, string $commandId, int $expectedRevision, string $eventType, callable $operation): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $expectedRevision, $eventType, $operation): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $snapshot = OverlayState::query()->where('live_session_id', $session->id)->lockForUpdate()->first();
            if ($snapshot === null) {
                $snapshot = OverlayState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'state' => self::initialState()]);
            }
            if ($snapshot->revision !== $expectedRevision) {
                throw new StaleOverlayState($snapshot);
            }
            $state = $operation($this->normalize($snapshot->state));
            $snapshot->update(['state' => $state, 'revision' => $snapshot->revision + 1]);
            $snapshot->refresh();
            $response = ['data' => $snapshot->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'overlay_state', 'aggregate_id' => $snapshot->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'overlay_state_id' => $snapshot->id, 'revision' => $snapshot->revision], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'overlay_state', 'aggregate_id' => $snapshot->id, 'topic' => 'overlay_states.'.$session->id, 'payload' => ['event_type' => $eventType, 'revision' => $snapshot->revision], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function updateAt(array $state, string $lane, string $location, ?int $index, array $patch): array
    {
        $entry = $location === 'current' ? $state[$lane]['current'] : $state[$lane]['queue'][$index];
        if (! is_array($entry)) {
            abort(404, 'The overlay entry does not belong to this session.');
        }
        foreach (['content', 'duration_seconds', 'pinned'] as $field) {
            if (array_key_exists($field, $patch)) {
                $entry[$field] = $patch[$field];
            }
        }
        $targetLane = $patch['placement'] ?? $lane;
        if (! is_string($targetLane)) {
            abort(422, 'The overlay placement is invalid.');
        }
        $this->assertLane($targetLane);
        if ($targetLane === $lane) {
            if ($location === 'current') {
                $state[$lane]['current'] = $entry;
            } else {
                $state[$lane]['queue'][$index] = $entry;
            }

            return $state;
        }
        if ($location === 'current') {
            $state[$lane]['current'] = null;
            $this->promote($state, $lane);
        } else {
            if ($index === null) {
                abort(404, 'The overlay entry does not belong to this session.');
            }
            array_splice($state[$lane]['queue'], $index, 1);
        }
        if ($state[$targetLane]['current'] === null) {
            $state[$targetLane]['current'] = $entry;
        } else {
            $state[$targetLane]['queue'][] = $entry;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function promote(array &$state, string $lane): void
    {
        $next = array_shift($state[$lane]['queue']);
        $state[$lane]['current'] = is_array($next) ? $next : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function entry(array $input): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'content' => $input['content'],
            'duration_seconds' => (int) $input['duration_seconds'],
            'pinned' => (bool) $input['pinned'],
            'source_type' => $input['source_type'] ?? null,
            'source_id' => $input['source_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        $normalized = self::initialState();
        foreach (self::LANES as $lane) {
            $candidate = $state[$lane] ?? null;
            if (! is_array($candidate)) {
                continue;
            }
            $normalized[$lane]['current'] = is_array($candidate['current'] ?? null) ? $candidate['current'] : null;
            foreach ($candidate['queue'] ?? [] as $entry) {
                if (is_array($entry)) {
                    $normalized[$lane]['queue'][] = $entry;
                }
            }
        }

        return $normalized;
    }

    private function assertLane(string $lane): void
    {
        abort_unless(in_array($lane, self::LANES, true), 422, 'The overlay lane must be corner or full.');
    }
}
