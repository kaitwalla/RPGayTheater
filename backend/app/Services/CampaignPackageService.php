<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use RuntimeException;
use ZipArchive;

class CampaignPackageService
{
    public function __construct(private readonly S3MultipartUploadService $storage) {}

    /** @return array{path: string, filename: string} */
    public function export(CampaignRevision $revision): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rpgays-package-');
        if ($path === false) {
            throw new RuntimeException('Unable to create package workspace.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create campaign package.');
        }
        $manifest = $revision->manifest;
        $media = [];
        foreach ($manifest['assets'] as $asset) {
            if (! is_array($asset) || ! isset($asset['id'], $asset['sha256'], $asset['storage_key'])) {
                throw new RuntimeException('The revision asset manifest is invalid.');
            }
            $entry = 'media/'.$asset['sha256'];
            $contents = stream_get_contents($this->storage->read((string) $asset['storage_key']));
            if ($contents === false) {
                throw new RuntimeException('Unable to read a packaged asset.');
            }
            $zip->addFromString($entry, $contents);
            $media[] = ['asset_id' => $asset['id'], 'path' => $entry, 'sha256' => $asset['sha256']];
        }
        $metadata = ['package_schema_version' => 1, 'campaign_revision_id' => $revision->getKey(), 'campaign_id' => $revision->campaign_id, 'revision_number' => $revision->number, 'manifest_hash' => $revision->manifest_hash, 'media' => $media];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('package.json', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return ['path' => $path, 'filename' => "campaign-{$revision->campaign_id}-revision-{$revision->number}.zip"];
    }
}
