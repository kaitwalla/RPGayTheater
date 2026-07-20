<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ArchiveCampaignAssetRequest;
use App\Http\Requests\CompleteAssetUploadRequest;
use App\Http\Requests\InitiateAssetUploadRequest;
use App\Models\CampaignAsset;
use App\Services\AssetUploadService;
use App\Services\S3MultipartUploadService;
use Illuminate\Http\JsonResponse;

class ControlAssetController extends Controller
{
    public function __construct(private readonly AssetUploadService $uploads, private readonly S3MultipartUploadService $storage) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => CampaignAsset::query()->where('campaign_id', $campaign)->latest()->get()->map->toApi()->values()]);
    }

    public function initiate(InitiateAssetUploadRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->initiate($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('original_filename')->toString(), $request->string('kind')->toString(), $request->string('declared_mime')->toString(), $request->integer('byte_size'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function complete(CompleteAssetUploadRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->complete($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->input('parts'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function read(string $campaign, string $asset): JsonResponse
    {
        /** @var CampaignAsset $asset */
        $asset = CampaignAsset::query()->where('campaign_id', $campaign)->findOrFail($asset);
        abort_unless($asset->upload_status === CampaignAsset::STATUS_READY && $asset->storage_key !== null, 422, 'This asset is not ready to read.');

        return response()->json(['data' => ['url' => $this->storage->signedReadUrl($asset->storage_key)]]);
    }

    public function destroy(ArchiveCampaignAssetRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->archive($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
