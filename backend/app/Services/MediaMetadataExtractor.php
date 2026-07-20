<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class MediaMetadataExtractor
{
    /** @return array{duration_seconds: float} */
    public function duration(string $path): array
    {
        $result = Process::timeout(15)->run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('The uploaded media could not be decoded for duration validation.');
        }
        $duration = trim($result->output());
        if (! is_numeric($duration) || ! is_finite((float) $duration) || (float) $duration <= 0) {
            throw new RuntimeException('The uploaded media has no usable duration.');
        }

        return ['duration_seconds' => round((float) $duration, 3)];
    }
}
