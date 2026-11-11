<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Beacon\Addons\AddonStore;
use Pterodactyl\Beacon\Addons\AddonArchive;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AddonStoreTest extends TestCase
{
    public function testLargePreviewsDoNotHaveTheOldSourceQuotaOrPreparedArchiveSizeCap(): void
    {
        $directory = sys_get_temp_dir() . '/beacon-large-store-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $input = $directory . '/input';
        $handle = fopen($input, 'wb');
        ftruncate($handle, 268435457);
        fclose($handle);
        $archive = \Mockery::mock(AddonArchive::class);
        $archive->expects('inspect')->once()->andReturnUsing(function ($source, $target) {
            $handle = fopen($target, 'wb');
            ftruncate($handle, 41943040);
            fclose($handle);

            return ['packs' => [], 'file_count' => 1, 'total_bytes' => 41943040];
        });
        $store = new class ($archive) extends AddonStore {
            public string $testDirectory;

            public function directory(Server $server): string
            {
                return $this->testDirectory;
            }
        };
        $store->testDirectory = $directory;
        try {
            $server = new Server();
            $data = $store->prepare($server, $input, ['provider' => 'upload']);
            $this->assertSame(41943040, filesize($store->path($server, $data['archive_id'])));
            $this->assertSame($data, $store->load($server, $data['archive_id']));
        } finally {
            foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.*') ?: []) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
            \Mockery::close();
        }
    }

    public function testRepeatedPreviewCannotReplaceImmutableMetadataAndChangedBytesAreRejected(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'beacon-store-');
        unlink($directory);
        mkdir($directory, 0700);
        $input = tempnam(sys_get_temp_dir(), 'beacon-input-');
        file_put_contents($input, 'source fixture');
        $archive = \Mockery::mock(AddonArchive::class);
        $archive->allows('inspect')->andReturnUsing(function ($source, $target) {
            file_put_contents($target, 'clean fixture');

            return ['packs' => [], 'file_count' => 0, 'total_bytes' => 0];
        });
        $store = new class ($archive) extends AddonStore {
            public string $testDirectory;

            public function directory(Server $server): string
            {
                return $this->testDirectory;
            }
        };
        $store->testDirectory = $directory;
        try {
            $server = new Server();
            $first = $store->prepare($server, $input, ['provider' => 'upload']);
            $this->assertSame($first, $store->prepare($server, $input, ['provider' => 'curseforge']));
            $this->assertCount(2, glob($directory . '/*') ?: []);
            file_put_contents($directory . '/' . $first['archive_id'] . '.zip', 'modified fixture');
            $this->expectException(ConflictHttpException::class);
            $store->load($server, $first['archive_id']);
        } finally {
            foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.*') ?: []) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
            unlink($input);
            \Mockery::close();
        }
    }
}
