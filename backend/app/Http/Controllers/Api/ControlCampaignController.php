<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Services\CampaignCommandService;
use App\Services\CampaignPackageService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ControlCampaignController extends Controller
{
    public function __construct(private readonly CampaignCommandService $commands, private readonly CampaignPackageService $packages) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Campaign::query()->whereNull('archived_at')->orderBy('name')->get()->map->toApi()->values(),
        ]);
    }

    public function store(CreateCampaignRequest $request): JsonResponse
    {
        [$response, $replayed] = $this->commands->create($request->string('command_id')->toString(), $request->string('name')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function update(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->rename(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
                $request->string('name')->toString(),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->archive(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function publish(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->publish(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function revisions(string $campaign): JsonResponse
    {
        return response()->json(['data' => CampaignRevision::query()->where('campaign_id', $campaign)->orderByDesc('number')->get()->map->toApi()->values()]);
    }

    public function revision(string $campaign, string $revision): JsonResponse
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->where('campaign_id', $campaign)->findOrFail($revision);

        return response()->json(['data' => $revision->toApi() + ['manifest' => $revision->manifest]]);
    }

    public function exportRevision(string $campaign, string $revision): BinaryFileResponse
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->where('campaign_id', $campaign)->findOrFail($revision);
        $package = $this->packages->export($revision);

        return response()->download($package['path'], $package['filename'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
