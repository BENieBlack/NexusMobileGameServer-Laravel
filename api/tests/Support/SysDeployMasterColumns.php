<?php

namespace Tests\Support;

/**
 * sys_deploy_master の版の DB 関連の必須列（NOT NULL）をテストで埋めるための値。
 * 設計: docs/design/master-distribution/db.md 3.1
 */
final class SysDeployMasterColumns
{
    /**
     * @return array{mst_database: string, artifact_dir: string, schema_fingerprint: string, source_kind: string}
     */
    public static function for(int $deployKey, string $hash): array
    {
        $hash16 = substr(hash('sha256', $hash), 0, 16);

        return [
            'mst_database' => "nexus-testing-master-{$deployKey}-{$hash16}",
            'artifact_dir' => "{$deployKey}_{$hash16}",
            'schema_fingerprint' => hash('sha256', 'schema_test'),
            'source_kind' => 'build',
        ];
    }
}
