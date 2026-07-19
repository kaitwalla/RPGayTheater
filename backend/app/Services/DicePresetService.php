<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\DicePreset;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;

class DicePresetService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, string $expression, string $visibility, bool $isDefault): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $expression, $visibility, $isDefault): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            if ($isDefault) {
                DicePreset::query()->where('campaign_id', $campaignId)->where('is_default', true)->update(['is_default' => false]);
            }
            $preset = DicePreset::query()->create(['campaign_id' => $campaignId, 'name' => trim($name), 'expression' => preg_replace('/\s+/', '', $expression), 'default_visibility' => $visibility, 'is_default' => $isDefault, 'sort_order' => (int) DicePreset::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $preset->id, 'name' => $preset->name, 'expression' => $preset->expression, 'default_visibility' => $preset->default_visibility, 'is_default' => $preset->is_default]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
