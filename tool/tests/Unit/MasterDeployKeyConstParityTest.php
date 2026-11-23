<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tool の config/master_deploy.php と api 側 packages/nexus-version の DeployKeyConst の数値が一致することを確かめる。
 * tool は packages のクラスを使わないので、定義ファイルを読んで定数の値を取り出す。
 */
class MasterDeployKeyConstParityTest extends TestCase
{
    /** @return array<string, int> */
    private function apiConstants(): array
    {
        $path = dirname(__DIR__, 3).'/packages/nexus-version/src/Constants/DeployKeyConst.php';
        $this->assertFileExists($path, 'api 側の DeployKeyConst が読めない（docker では ./packages を /var/www/packages に mount している）');
        $source = (string) file_get_contents($path);
        preg_match_all('/const\s+([A-Z_]+)\s*=\s*(\d+)\s*;/', $source, $m, PREG_SET_ORDER);
        $result = [];
        foreach ($m as $row) {
            $result[$row[1]] = (int) $row[2];
        }

        return $result;
    }

    public function test_tool_の設定と_api_の_deploy_key_const_が一致する(): void
    {
        $config = require dirname(__DIR__, 2).'/config/master_deploy.php';
        $api = $this->apiConstants();
        foreach (['RESERVED_FLOOR', 'FIXED_KEY_RECORDING', 'FIXED_KEY_MID', 'FIXED_KEY_TOP', 'REAL_KEY_MAX'] as $name) {
            $this->assertArrayHasKey($name, $api, "DeployKeyConst::{$name} が読めない");
        }

        $this->assertSame($api['RESERVED_FLOOR'], $config['reserved_floor']);
        $this->assertSame(
            [$api['FIXED_KEY_RECORDING'], $api['FIXED_KEY_MID'], $api['FIXED_KEY_TOP']],
            $config['allowed_fixed_keys'],
        );
        // REAL_KEY_MAX は tool に持たない（比較は api 側の定数どうしの整合だけ）
        $this->assertGreaterThan($api['REAL_KEY_MAX'], $api['RESERVED_FLOOR']);
    }
}
