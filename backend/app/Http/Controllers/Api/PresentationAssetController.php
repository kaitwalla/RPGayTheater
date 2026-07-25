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
        $record = $this->readableAsset($request, $asset);

        return response()->json(['data' => ['url' => $this->versionedContentUrl($record)]]);
    }

    public function content(Request $request, string $asset): StreamedResponse
    {
        $asset = $this->readableAsset($request, $asset);
        $range = $this->requestedRange($request, $asset);
        if ($range === false) {
            return response()->stream(static function (): void {}, 416, [
                'Content-Range' => 'bytes */'.$asset->byte_size,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        return response()->stream(function () use ($asset, $range): void {
            $stream = $range === null
                ? $this->storage->read((string) $asset->storage_key)
                : $this->storage->read((string) $asset->storage_key, $range['header']);
            fpassthru($stream);
            fclose($stream);
        }, $range === null ? 200 : 206, $this->contentHeaders($asset, $range));
    }

    /**
     * @return array{header: string, start: int, end: int, length: int}|false|null
     */
    private function requestedRange(Request $request, CampaignAsset $asset): array|false|null
    {
        $header = $request->header('Range');
        if (! is_string($header) || $header === '') {
            return null;
        }
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }

        $size = max(0, $asset->byte_size);
        if ($size === 0) {
            return false;
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        }

        if ($start > $end || $start >= $size) {
            return false;
        }

        return ['header' => "bytes={$start}-{$end}", 'start' => $start, 'end' => $end, 'length' => $end - $start + 1];
    }

    /**
     * @param  array{header: string, start: int, end: int, length: int}|null  $range
     * @return array<string, string|int>
     */
    private function contentHeaders(CampaignAsset $asset, ?array $range): array
    {
        $headers = [
            'Content-Type' => $asset->validated_mime ?: $asset->declared_mime,
            'Cache-Control' => 'private, max-age=300',
            'Accept-Ranges' => 'bytes',
        ];
        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$range['start']}-{$range['end']}/{$asset->byte_size}";
            $headers['Content-Length'] = $range['length'];
        }

        return $headers;
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

    private function versionedContentUrl(CampaignAsset $asset): string
    {
        $url = url("/api/presentation/v1/assets/{$asset->getKey()}/content");

        return $asset->sha256 === null ? $url : $url.'?v='.rawurlencode($asset->sha256);
    }
}
