<?php

namespace Tests\Unit;

use App\Exceptions\MasterDeployKeyException;
use App\Services\MasterDeployKeyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MasterDeployKeyPolicyTest extends TestCase
{
    private function policy(mixed $fixedKey = null, string $env = 'local'): MasterDeployKeyPolicy
    {
        return new MasterDeployKeyPolicy(
            $fixedKey,
            [7777777777, 8888888888, 9999999999],
            ['production', 'staging'],
            7000000000,
            $env,
        );
    }

    private function codeOf(callable $fn): ?string
    {
        try {
            $fn();
        } catch (MasterDeployKeyException $e) {
            return $e->errorCode;
        }

        return null;
    }

    public function test_未設定なら_null_で本物の採番(): void
    {
        $this->assertNull($this->policy(null, 'production')->resolveFixedKey());
    }

    #[DataProvider('allowedKeys')]
    public function test_許可リストの固定キーは非本番で通る(int $key): void
    {
        $this->assertSame($key, $this->policy($key, 'local')->resolveFixedKey());
    }

    /** @return array<string, array{int}> */
    public static function allowedKeys(): array
    {
        return ['録画' => [7777777777], 'LD' => [8888888888], '開発' => [9999999999]];
    }

    #[DataProvider('invalidKeys')]
    public function test_許可リスト外は_fixed_key_invalid(mixed $key): void
    {
        $code = $this->codeOf(fn () => $this->policy($key)->resolveFixedKey());
        $this->assertSame('fixed_key_invalid', $code);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidKeys(): array
    {
        return [
            '誤記' => [7777777778],
            '予約帯の別の値' => [7000000000],
            '本物のキー' => [2026010101],
            '文字列' => ['abc'],
            '数字の文字列は config で int にされる前提' => ['9999999999'],
            '0' => [0],
        ];
    }

    #[DataProvider('forbiddenEnvs')]
    public function test_禁止環境で固定キーがあれば_fixed_key_forbidden(string $env): void
    {
        $e = null;
        try {
            $this->policy(9999999999, $env)->resolveFixedKey();
        } catch (MasterDeployKeyException $e) {
        }
        $this->assertNotNull($e);
        $this->assertSame('fixed_key_forbidden', $e->errorCode);
        $this->assertSame(403, $e->httpStatus);
    }

    /** @return array<string, array{string}> */
    public static function forbiddenEnvs(): array
    {
        return ['本番' => ['production'], 'ステージング' => ['staging']];
    }

    public function test_禁止環境でも固定キーが無ければ通る(): void
    {
        $this->assertNull($this->policy(null, 'staging')->resolveFixedKey());
    }

    public function test_許可リスト外は禁止環境でも_invalid_が先(): void
    {
        $this->assertSame('fixed_key_invalid', $this->codeOf(fn () => $this->policy(1, 'production')->resolveFixedKey()));
    }

    public function test_採番の結果が予約帯以上なら_reserved_key_forbidden(): void
    {
        $p = $this->policy();
        $p->assertRealKey(2999123199);
        $p->assertRealKey(6999999999);
        foreach ([7000000000, 7777777777, 9999999999] as $key) {
            $this->assertSame('reserved_key_forbidden', $this->codeOf(fn () => $p->assertRealKey($key)));
        }
    }

    public function test_予約帯のうち固定キー以外は拒否(): void
    {
        $p = $this->policy();
        foreach ([7000000000, 7777777778, 8888888889, 9999999998] as $key) {
            $this->assertTrue($p->isRejectedReservedKey($key), (string) $key);
            $this->assertSame('reserved_key_forbidden', $this->codeOf(fn () => $p->assertNotRejectedReservedKey($key)));
        }
        foreach ([7777777777, 8888888888, 9999999999, 2026010101, 6999999999] as $key) {
            $this->assertFalse($p->isRejectedReservedKey($key), (string) $key);
            $p->assertNotRejectedReservedKey($key);
        }
    }

    #[DataProvider('envValues')]
    public function test_環境変数の値の変換(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, MasterDeployKeyPolicy::parseFixedKeyEnv($input));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function envValues(): array
    {
        return [
            '未設定' => [null, null],
            '空' => ['', null],
            '空白' => ['  ', null],
            '数字' => ['9999999999', 9999999999],
            '前後の空白' => [' 8888888888 ', 8888888888],
            '先頭ゼロは文字列のまま' => ['07777777777', '07777777777'],
            '不正な文字列' => ['abc', 'abc'],
            '符号' => ['-1', '-1'],
            '小数' => ['1.5', '1.5'],
            '桁あふれは文字列のまま' => ['99999999999999999999', '99999999999999999999'],
        ];
    }

    public function test_先頭ゼロ・桁あふれは_fixed_key_invalid(): void
    {
        foreach (['07777777777', '99999999999999999999'] as $raw) {
            $key = MasterDeployKeyPolicy::parseFixedKeyEnv($raw);
            $this->assertSame('fixed_key_invalid', $this->codeOf(fn () => $this->policy($key)->resolveFixedKey()), $raw);
        }
    }

    public function test_禁止環境の判定は大文字小文字と空白を無視し_未知の_env_は通る(): void
    {
        $this->assertSame('fixed_key_forbidden', $this->codeOf(fn () => $this->policy(9999999999, ' Production ')->resolveFixedKey()));
        $this->assertSame(9999999999, $this->policy(9999999999, 'qa')->resolveFixedKey());
    }
}
