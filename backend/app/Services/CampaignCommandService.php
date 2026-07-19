<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;

class CampaignCommandService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $commandId, string $name): array
    {
        return DB::transaction(function () use ($commandId, $name): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }

            $campaign = Campaign::query()->create(['name' => trim($name)]);
            $campaign->refresh();
            $response = ['data' => $campaign->toApi()];
            $this->record($commandId, $campaign, 'campaign.created', $response);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function rename(string $campaignId, string $commandId, int $expectedRevision, string $name): array
    {
        return $this->change($campaignId, $commandId, $expectedRevision, 'campaign.renamed', function (Campaign $campaign) use ($name): void {
            $campaign->name = trim($name);
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function archive(string $campaignId, string $commandId, int $expectedRevision): array
    {
        return $this->change($campaignId, $commandId, $expectedRevision, 'campaign.archived', function (Campaign $campaign): void {
            $campaign->archived_at = now();
        });
    }

    /**
     * @param callable(Campaign): void $change
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function change(string $campaignId, string $commandId, int $expectedRevision, string $eventType, callable $change): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $eventType, $change): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }

            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }

            $change($campaign);
            $campaign->draft_revision++;
            $campaign->save();

            $response = ['data' => $campaign->fresh()->toApi()];
            $this->record($commandId, $campaign, $eventType, $response);

            return [$response, false];
        });
    }

    /** @return array<string, mixed>|null */
    private function previousResponse(string $commandId): ?array
    {
        return ProcessedCommand::query()->find($commandId)?->response;
    }

    /** @param array<string, mixed> $response */
    private function record(string $commandId, Campaign $campaign, string $eventType, array $response): void
    {
        ProcessedCommand::query()->create([
            'command_id' => $commandId,
            'aggregate_type' => 'campaign',
            'aggregate_id' => $campaign->getKey(),
            'response' => $response,
        ]);

        $payload = ['campaign' => $response['data']];
        SessionEvent::query()->create([
            'campaign_id' => $campaign->getKey(),
            'actor_type' => 'control',
            'event_type' => $eventType,
            'command_id' => $commandId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
        OutboxEvent::query()->create([
            'aggregate_type' => 'campaign',
            'aggregate_id' => $campaign->getKey(),
            'topic' => 'control.campaigns',
            'payload' => ['event_type' => $eventType, 'command_id' => $commandId, 'revision' => $campaign->draft_revision],
            'occurred_at' => now(),
        ]);
    }
}
