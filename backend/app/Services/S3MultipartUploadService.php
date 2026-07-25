<?php

declare(strict_types=1);

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class S3MultipartUploadService
{
    public function __construct(private readonly ?S3Client $s3Client = null) {}

    /** @return array{upload_id: string, part_size: int, parts: list<array{number: int, url: string}>} */
    public function initiate(string $key, string $mime, int $byteSize): array
    {
        $partSize = (int) config('assets.part_size_bytes');
        $partCount = (int) ceil($byteSize / $partSize);
        if ($partCount > 10_000) {
            throw new RuntimeException('The asset would exceed the multipart-upload part limit.');
        }

        $storageClient = $this->client();
        $result = $storageClient->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $mime,
        ]);
        $uploadId = (string) $result->get('UploadId');
        $expires = sprintf('+%d minutes', (int) config('assets.signed_url_minutes'));
        $browserClient = $this->browserClient();
        $parts = [];
        for ($number = 1; $number <= $partCount; $number++) {
            $request = $browserClient->createPresignedRequest($browserClient->getCommand('UploadPart', [
                'Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $number,
            ]), $expires);
            $parts[] = ['number' => $number, 'url' => (string) $request->getUri()];
        }

        return ['upload_id' => $uploadId, 'part_size' => $partSize, 'parts' => $parts];
    }

    /** @param list<array{number: int, e_tag?: string}> $parts */
    public function complete(string $key, string $uploadId, array $parts): void
    {
        $storageClient = $this->client();
        $partsNeedingLookup = array_filter($parts, static fn (array $part): bool => ! isset($part['e_tag']) || $part['e_tag'] === '');
        $uploadedParts = $partsNeedingLookup === [] ? [] : $this->uploadedParts($storageClient, $key, $uploadId);
        $completionParts = [];
        foreach ($parts as $part) {
            $number = $part['number'];
            $eTag = $part['e_tag'] ?? $uploadedParts[$number] ?? null;
            if (! is_string($eTag) || $eTag === '') {
                throw new RuntimeException("Uploaded storage part {$number} could not be verified.");
            }
            $completionParts[] = ['PartNumber' => $number, 'ETag' => $eTag];
        }
        usort($completionParts, static fn (array $left, array $right): int => $left['PartNumber'] <=> $right['PartNumber']);

        $storageClient->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $completionParts],
        ]);
    }

    /** @return resource */
    public function read(string $key, ?string $range = null)
    {
        $parameters = ['Bucket' => $this->bucket(), 'Key' => $key];
        if ($range !== null) {
            $parameters['Range'] = $range;
        }

        $stream = $this->client()->getObject($parameters)->get('Body')->detach();
        if (! is_resource($stream)) {
            throw new RuntimeException('The completed object could not be read.');
        }

        return $stream;
    }

    public function promote(string $sourceKey, string $destinationKey): void
    {
        $this->client()->copyObject(['Bucket' => $this->bucket(), 'Key' => $destinationKey, 'CopySource' => $this->bucket().'/'.$sourceKey]);
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $sourceKey]);
    }

    /** @param resource $contents */
    public function put(string $key, $contents, string $mime): void
    {
        $this->client()->putObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => $mime,
        ]);
    }

    public function delete(string $key): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
    }

    public function signedReadUrl(string $key): string
    {
        $client = $this->browserClient();
        $request = $client->createPresignedRequest($client->getCommand('GetObject', [
            'Bucket' => $this->bucket(), 'Key' => $key,
        ]), sprintf('+%d minutes', (int) config('assets.signed_url_minutes')));

        return (string) $request->getUri();
    }

    private function client(): S3Client
    {
        if ($this->s3Client instanceof S3Client) {
            return $this->s3Client;
        }

        $disk = Config::array('filesystems.disks.'.config('assets.disk'));
        abort_unless(($disk['driver'] ?? null) === 's3', 503, 'Direct multipart uploads require an S3-compatible asset disk.');

        return new S3Client([
            'version' => 'latest', 'region' => $disk['region'], 'endpoint' => $disk['endpoint'],
            'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);
    }

    private function browserClient(): S3Client
    {
        $publicEndpoint = config('assets.public_s3_endpoint');
        if (! is_string($publicEndpoint) || $publicEndpoint === '') {
            return $this->client();
        }

        $disk = Config::array('filesystems.disks.'.config('assets.disk'));
        abort_unless(($disk['driver'] ?? null) === 's3', 503, 'Direct multipart uploads require an S3-compatible asset disk.');

        return new S3Client([
            'version' => 'latest', 'region' => $disk['region'], 'endpoint' => $publicEndpoint,
            'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);
    }

    private function bucket(): string
    {
        return (string) Config::get('filesystems.disks.'.config('assets.disk').'.bucket');
    }

    /** @return array<int, string> */
    private function uploadedParts(S3Client $storageClient, string $key, string $uploadId): array
    {
        $parts = [];
        $partNumberMarker = null;
        do {
            $parameters = ['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId];
            if ($partNumberMarker !== null) {
                $parameters['PartNumberMarker'] = $partNumberMarker;
            }
            $result = $storageClient->listParts($parameters);
            foreach ($result->get('Parts') ?? [] as $part) {
                $number = $part['PartNumber'] ?? null;
                $eTag = $part['ETag'] ?? null;
                if (is_numeric($number) && is_string($eTag)) {
                    $parts[(int) $number] = $eTag;
                }
            }
            $nextMarker = $result->get('NextPartNumberMarker');
            $partNumberMarker = $result->get('IsTruncated') === true && is_numeric($nextMarker) ? (int) $nextMarker : null;
        } while ($partNumberMarker !== null);

        return $parts;
    }
}
