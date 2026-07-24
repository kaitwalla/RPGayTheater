<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignAsset;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use App\Services\PresentationRenderService;
use App\Services\S3MultipartUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PresentationAssetController extends Controller
{
    public function __construct(private readonly PresentationRenderService $render, private readonly S3MultipartUploadService $storage) {}

    public function read(Request $request, string $asset): JsonResponse
    {
        $this->readableAsset($request, $asset);

        return response()->json(['data' => ['url' => url("/api/presentation/v1/assets/{$asset}/content")]]);
    }

    public function content(Request $request, string $asset): StreamedResponse
    {
        $asset = $this->readableAsset($request, $asset);

        return response()->stream(function () use ($asset): void {
            $stream = $this->storage->read((string) $asset->storage_key);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $asset->validated_mime ?: $asset->declared_mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function readableAsset(Request $request, string $asset): CampaignAsset
    {
        $displayId = $request->session()->get('presentation.display_id');
        abort_unless(is_string($displayId), 401, 'Presentation authentication is required.');
        /** @var PresentationDisplay $display */
        $display = PresentationDisplay::query()->whereNull('revoked_at')->findOrFail($displayId);
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($display->live_session_id);
        abort_unless(in_array($asset, $this->render->allowedAssetIds($session), true), 404, 'This asset is not available to this Presentation.');
        /** @var CampaignAsset $record */
        $record = CampaignAsset::query()->where('campaign_id', $session->campaign_id)->findOrFail($asset);
        abort_unless($record->upload_status === CampaignAsset::STATUS_READY && $record->storage_key !== null, 422, 'This asset is not ready to read.');

        return $record;
    }
}
