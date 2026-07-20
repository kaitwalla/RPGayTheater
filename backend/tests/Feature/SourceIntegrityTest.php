<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class SourceIntegrityTest extends TestCase
{
    /** @var list<string> */
    private const SOURCE_DIRECTORIES = ['app', 'resources', 'routes'];

    public function test_shippable_sources_do_not_contain_provisional_implementation_markers(): void
    {
        $pattern = '/\b(?:TODO|FIXME|HACK|XXX|@todo)\b|not implemented|coming soon/i';

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                base_path($directory),
                FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                self::assertInstanceOf(SplFileInfo::class, $file);
                if (! $file->isFile()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents, sprintf('Unable to read %s.', $file->getPathname()));
                self::assertSame(
                    0,
                    preg_match($pattern, $contents),
                    sprintf(
                        '%s contains a provisional implementation marker.',
                        str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                    ),
                );
            }
        }
    }
}
