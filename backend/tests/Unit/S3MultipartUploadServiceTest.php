<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\S3MultipartUploadService;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class S3MultipartUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_all_presigned_parts_and_rejects_an_oversized_upload(): void
    {
        config()->set('assets.part_size_bytes', 5);
        $handler = new MockHandler([new Result(['UploadId' => 'upload-123'])]);
        $service = $this->service($handler);

        $upload = $service->initiate('staging/avatar.png', 'image/png', 11);

        self::assertSame('upload-123', $upload['upload_id']);
        self::assertSame(5, $upload['part_size']);
        self::assertSame([1, 2, 3], array_column($upload['parts'], 'number'));
        self::assertCount(3, array_filter($upload['parts'], static fn (array $part): bool => str_contains($part['url'], 'X-Amz-Signature')));
        self::assertSame('CreateMultipartUpload', $handler->getLastCommand()->getName());

        config()->set('assets.part_size_bytes', 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('part limit');
        $service->initiate('staging/too-large.bin', 'application/octet-stream', 10_001);
    }

    public function test_it_completes_reads_promotes_writes_and_deletes_multipart_objects(): void
    {
        $handler = new MockHandler([
            new Result,
            new Result(['Body' => Utils::streamFor('completed bytes')]),
            new Result,
            new Result,
            new Result,
            new Result,
        ]);
        $service = $this->service($handler);

        $service->complete('staging/avatar.png', 'upload-123', [['number' => 1, 'e_tag' => 'etag-1']]);
        $stream = $service->read('staging/avatar.png');
        $service->promote('staging/avatar.png', 'assets/avatar.png');
        $service->put('assets/avatar.png', Utils::streamFor('replacement bytes'), 'image/png');
        $service->delete('assets/avatar.png');

        self::assertSame('completed bytes', stream_get_contents($stream));
        self::assertSame(0, $handler->count());
        self::assertSame('DeleteObject', $handler->getLastCommand()->getName());
    }

    public function test_it_generates_signed_reads_and_rejects_non_s3_asset_disks(): void
    {
        $url = $this->service(new MockHandler)->signedReadUrl('assets/avatar.png');
        self::assertStringContainsString('X-Amz-Signature', $url);

        config()->set('assets.disk', 'local');
        try {
            (new S3MultipartUploadService)->delete('assets/avatar.png');
            self::fail('A non-S3 asset disk must reject direct multipart storage operations.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }
    }

    private function service(MockHandler $handler): S3MultipartUploadService
    {
        return new S3MultipartUploadService(new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]));
    }
}
