<?php

namespace Tests\Feature;

use App\Services\MasterArtifactStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterArtifactStore（成果物の置き場。db.md 6.5.3）のテスト
 */
class MasterArtifactStoreTest extends TestCase
{
    private string $localDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->localDir = sys_get_temp_dir().'/store-test-'.bin2hex(random_bytes(4));
        mkdir($this->localDir.'/data', 0755, true);
        file_put_contents($this->localDir.'/schema.json', '{}');
        file_put_contents($this->localDir.'/data/mst_a.ndjson', "{}\n");
        file_put_contents($this->localDir.'/mst_a_h.sqlite', 'x');
        file_put_contents($this->localDir.'/manifest.json', '{"hash":"h"}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->localDir);
        parent::tearDown();
    }

    #[Test]
    public function disk名はmaster_artifactでローカルの置き場はpublic_masterdata_と_asset(): void
    {
        $this->assertSame('master_artifact', MasterArtifactStore::DISK);
        $this->assertSame(public_path('masterdata'), config('filesystems.disks.master_artifact.root'));
        $this->assertSame(public_path('asset'), config('filesystems.disks.asset_artifact.root'));
        $this->assertArrayHasKey('master_artifact_upstream', config('filesystems.disks'));
    }

    #[Test]
    public function manifest_jsonは最後に置く_完成の印(): void
    {
        $written = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->andReturnUsing(function (string $path) use (&$written): bool {
            $written[] = $path;

            return true;
        });
        Storage::set(MasterArtifactStore::DISK, $disk);

        (new MasterArtifactStore)->put($this->localDir, '2026120101_abcdef0123456789');

        $this->assertCount(4, $written);
        $this->assertSame('2026120101_abcdef0123456789/manifest.json', end($written));
        $this->assertContains('2026120101_abcdef0123456789/data/mst_a.ndjson', $written);
    }

    #[Test]
    public function manifest_jsonが無いディレクトリは未完成_置くときもmanifestが要る(): void
    {
        Storage::fake(MasterArtifactStore::DISK);
        $store = new MasterArtifactStore;
        Storage::disk(MasterArtifactStore::DISK)->put('2026120101_aaaaaaaaaaaaaaaa/schema.json', '{}');

        $this->assertFalse($store->isComplete('2026120101_aaaaaaaaaaaaaaaa'));
        $this->assertNull($store->manifest('2026120101_aaaaaaaaaaaaaaaa'));

        unlink($this->localDir.'/manifest.json');
        $this->expectException(RuntimeException::class);
        $store->put($this->localDir, '2026120101_bbbbbbbbbbbbbbbb');
    }

    #[Test]
    public function 置いた成果物はmanifestを読める(): void
    {
        Storage::fake(MasterArtifactStore::DISK);
        $store = new MasterArtifactStore;

        $store->put($this->localDir, '2026120101_cccccccccccccccc');

        $this->assertTrue($store->isComplete('2026120101_cccccccccccccccc'));
        $this->assertSame(['hash' => 'h'], $store->manifest('2026120101_cccccccccccccccc'));
        $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists('2026120101_cccccccccccccccc/data/mst_a.ndjson'));
    }
}
