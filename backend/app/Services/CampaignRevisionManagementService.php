<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;

class CampaignRevisionManagementService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function rename(string $campaignId, string $revisionId, string $commandId, string $name): array
    {
        return $this->change($campaignId, $revisionId, $commandId, function (CampaignRevision $revision) use ($name): void {
            $revision->name = trim($name);
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function archive(string $campaignId, string $revisionId, string $commandId): array
    {
        return $this->change($campaignId, $revisionId, $commandId, function (CampaignRevision $revision): void {
            abort_if($revision->archived_at !== null, 422, 'This revision is already archived.');
            $revision->archived_at = now()->toImmutable();
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function delete(string $campaignId, string $revisionId, string $commandId): array
    {
        return DB::transaction(function () use ($campaignId, $revisionId, $commandId): array {
            if ($response = $this->previous($commandId)) {
                return [$response, true];
            }
            /** @var CampaignRevision $revision */
            $revision = CampaignRevision::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($revisionId);
            abort_unless($revision->archived_at !== null, 422, 'Archive this revision before deleting it.');
            abort_if(LiveSession::query()->where('campaign_revision_id', $revision->getKey())->exists(), 422, 'This revision is still used by a live session and cannot be deleted.');

            $response = ['data' => ['id' => $revision->getKey()]];
            $revision->delete();
            $this->record($commandId, $revisionId, $response);

            return [$response, false];
        });
    }

    /**
     * @param  callable(CampaignRevision): void  $change
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function change(string $campaignId, string $revisionId, string $commandId, callable $change): array
    {
        return DB::transaction(function () use ($campaignId, $revisionId, $commandId, $change): array {
            if ($response = $this->previous($commandId)) {
                return [$response, true];
            }
            /** @var CampaignRevision $revision */
            $revision = CampaignRevision::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($revisionId);
            $change($revision);
            $revision->save();
            $revision->refresh();
            $response = ['data' => $revision->toApi()];
            $this->record($commandId, $revision->getKey(), $response);

            return [$response, false];
        });
    }

    /** @return array<string, mixed>|null */
    private function previous(string $commandId): ?array
    {
        return ProcessedCommand::query()->find($commandId)?->response;
    }

    /** @param array<string, mixed> $response */
    private function record(string $commandId, string $revisionId, array $response): void
    {
        ProcessedCommand::query()->create([
            'command_id' => $commandId,
            'aggregate_type' => 'campaign_revision',
            'aggregate_id' => $revisionId,
            'response' => $response,
        ]);
    }
}
