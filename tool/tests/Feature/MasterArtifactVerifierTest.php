<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterArtifactStore;
use App\Services\MasterArtifactVerifier;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterArtifactVerifier（I1）のテスト。builder が作った成果物が検証を通ること、必須項目が無ければ artifact_invalid になること
 */
class MasterArtifactVerifierTest extends MasterVersionedTestCase
{
    #[Test]
    public function builderが作った成果物は検証を通る(): void
    {
        $a = $this->buildVersion(2026112201);

        $manifest = (new MasterArtifactVerifier)->verify(Storage::disk(MasterArtifactStore::DISK), $a['artifact_dir'], $a['artifact_dir'], false);

        $this->assertSame($a['hash'], $manifest['hash']);
    }

    #[Test]
    public function schema_sha256やgroup_listが無いマニフェストはartifact_invalid(): void
    {
        $a = $this->buildVersion(2026112201);
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $original = json_decode($disk->get($a['artifact_dir'].'/manifest.json'), true);

        foreach (['schema_sha256', 'group_list'] as $key) {
            $manifest = $original;
            unset($manifest[$key]);
            $disk->put($a['artifact_dir'].'/manifest.json', json_encode($manifest));
            try {
                (new MasterArtifactVerifier)->verify($disk, $a['artifact_dir'], $a['artifact_dir'], false);
                $this->fail("{$key} が無ければ artifact_invalid");
            } catch (MasterDeployRunException $e) {
                $this->assertSame(MasterDeployRunException::ARTIFACT_INVALID, $e->errorCode, $key);
            }
        }
    }
}
