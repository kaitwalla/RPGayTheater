<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignAsset;
use App\Models\LiveSession;
use App\Models\SessionParticipant;
use App\Services\PlayerMapStateService;
use App\Services\S3MultipartUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParticipantMapAssetController extends Controller
{
    public function __construct(private readonly PlayerMapStateService $states, private readonly S3MultipartUploadService $storage) {}

    public function read(Request $request, string $asset): JsonResponse
    {
        $this->readableAsset($request, $asset);

        return response()->json(['data' => ['url' => url("/api/participant/v1/map/assets/{$asset}/content")]]);
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
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->whereNull('revoked_at')->findOrFail($participantId);
        $snapshot = $this->states->participantSnapshot($participant);
        $allowed = [];
        if (is_array($snapshot['map']) && is_string($snapshot['map']['image_asset_id'] ?? null)) {
            $allowed[] = $snapshot['map']['image_asset_id'];
        }
        foreach ($snapshot['progress']['tokens'] ?? [] as $token) {
            if (is_array($token) && is_string($token['asset_id'] ?? null)) {
                $allowed[] = $token['asset_id'];
            }
        }
        abort_unless(in_array($asset, $allowed, true), 404, 'This asset is not available on the current map.');
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        /** @var CampaignAsset $record */
        $record = CampaignAsset::query()->where('campaign_id', $session->campaign_id)->findOrFail($asset);
        abort_unless($record->upload_status === CampaignAsset::STATUS_READY && $record->storage_key !== null, 422, 'This asset is not ready to read.');

        return $record;
    }
}
