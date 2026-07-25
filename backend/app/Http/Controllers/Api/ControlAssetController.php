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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function initiateReplacement(InitiateAssetUploadRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->initiateReplacement($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('original_filename')->toString(), $request->string('kind')->toString(), $request->string('declared_mime')->toString(), $request->integer('byte_size'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function completeReplacement(CompleteAssetUploadRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->completeReplacement($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->input('parts'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function read(string $campaign, string $asset): JsonResponse
    {
        $this->readableAsset($campaign, $asset);

        return response()->json(['data' => ['url' => url("/api/control/v1/campaigns/{$campaign}/assets/{$asset}/content")]]);
    }

    public function content(string $campaign, string $asset): StreamedResponse
    {
        $asset = $this->readableAsset($campaign, $asset);
        $range = $this->requestedRange($asset);
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

    public function destroy(ArchiveCampaignAssetRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->archive($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function purge(ArchiveCampaignAssetRequest $request, string $campaign, string $asset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->uploads->purge($campaign, $asset, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    private function readableAsset(string $campaign, string $asset): CampaignAsset
    {
        /** @var CampaignAsset $record */
        $record = CampaignAsset::query()->where('campaign_id', $campaign)->findOrFail($asset);
        abort_unless($record->upload_status === CampaignAsset::STATUS_READY && $record->storage_key !== null, 422, 'This asset is not ready to read.');

        return $record;
    }

    /**
     * @return array{header: string, start: int, end: int, length: int}|false|null
     */
    private function requestedRange(CampaignAsset $asset): array|false|null
    {
        $header = request()->header('Range');
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
}
