<?php

namespace Tests\Feature;

use App\Services\MasterDeployLock;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterDeployLock（MySQL の GET_LOCK。sys 接続）のテスト
 *
 * ロックは接続ごとなので、同じ sys の DB を指す 2 つ目の接続を作って「他のプロセス」にする。
 * ロック名は DB 名を含むテスト用の sys DB だけに効く（他の環境のロックとは衝突しない）。
 */
class MasterDeployLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringContainsString('testing', DB::connection('sys')->getDatabaseName());
        Config::set('database.connections.sys_other', Config::get('database.connections.sys'));
        DB::purge('sys_other');
    }

    protected function tearDown(): void
    {
        DB::connection('sys')->selectOne('SELECT RELEASE_LOCK(?) AS r', [(new MasterDeployLock)->name()]);
        DB::purge('sys_other');
        DB::purge('sys');

        parent::tearDown();
    }

    private function otherHolds(string $name): bool
    {
        return (int) DB::connection('sys_other')->selectOne('SELECT GET_LOCK(?, 0) AS l', [$name])->l === 1;
    }

    #[Test]
    public function 名前はsysの_d_b名を含む(): void
    {
        $name = (new MasterDeployLock)->name();

        $this->assertSame('nexus_master_deploy:'.DB::connection('sys')->getDatabaseName(), $name);
        $this->assertLessThanOrEqual(64, strlen($name));
    }

    #[Test]
    public function 取れれば他の接続は待たずに取れず_解放すると取れる(): void
    {
        $lock = new MasterDeployLock;
        $name = $lock->name();

        $this->assertTrue($lock->acquire());
        $started = microtime(true);
        $this->assertFalse($this->otherHolds($name));
        $this->assertLessThan(1.0, microtime(true) - $started, '待たずに返る');

        $lock->release();
        $this->assertTrue($this->otherHolds($name));
        DB::connection('sys_other')->selectOne('SELECT RELEASE_LOCK(?) AS r', [$name]);
    }

    #[Test]
    public function 他の接続が持っていればacquireはfalse(): void
    {
        $lock = new MasterDeployLock;
        $this->assertTrue($this->otherHolds($lock->name()));

        $this->assertFalse($lock->acquire());

        DB::connection('sys_other')->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lock->name()]);
    }
}
