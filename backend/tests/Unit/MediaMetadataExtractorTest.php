<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaMetadataExtractor;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class MediaMetadataExtractorTest extends TestCase
{
    public function test_it_reads_and_normalizes_a_media_duration_with_ffprobe(): void
    {
        Process::fake(['*' => Process::result("12.34567\n")]);

        $metadata = $this->app->make(MediaMetadataExtractor::class)->duration('/tmp/clip.mp4');

        self::assertSame(['duration_seconds' => 12.346], $metadata);
    }

    public function test_it_rejects_media_that_ffprobe_cannot_decode(): void
    {
        Process::fake(['*' => Process::result('', 'invalid media', 1)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be decoded');
        $this->app->make(MediaMetadataExtractor::class)->duration('/tmp/broken.mp4');
    }
}
