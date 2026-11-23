<?php

namespace App\Console\Commands;

use App\Exceptions\MasterDeployRunException;
use App\Support\MasterDeployFailure;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Console\Command;
use Throwable;

/**
 * `master:*` コマンドの共通部分（結果の出力と終了コード）
 *
 * 設計: docs/design/master-distribution/api.md 10 節
 * 終了コード: 0 成功（dry run の成功・成功に付く警告を含む）/ 1 実行中の失敗・想定外 / 2 入力・シート・成果物の検証の失敗 /
 * 3 排他（他の操作が実行中）/ 4 環境・状態の前提で拒否（対応は MasterDeployFailure::exitCode）。
 * 出力は結果の表と error_list を標準出力に。日時は UTC のまま出し、JST を併記する（表示だけ。保存は UTC）。
 * 画面と同じ部品を呼ぶ。`adm_account_id` は渡さない（null で記録される）。
 */
abstract class MasterCommand extends Command
{
    /** 入力の形式の誤りの終了コード */
    protected const EXIT_INPUT = 2;

    /** 結果の表に出さないキー（見出しで出す） */
    private const HEADER_KEYS = ['status', 'message', 'run_id', 'code'];

    /**
     * 操作を実行して結果を出し、終了コードを返す
     *
     * @param  Closure(): array<string, mixed>  $operation  Runner / Service の呼び出し
     */
    protected function runOperation(Closure $operation): int
    {
        try {
            $result = $operation();
        } catch (Throwable $e) {
            $failure = MasterDeployFailure::from($e);
            $this->renderFailure($failure);

            return MasterDeployFailure::exitCode($failure->errorCode);
        }

        $this->renderSuccess($result);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function renderSuccess(array $result): void
    {
        $code = isset($result['code']) && is_string($result['code']) ? " ({$result['code']})" : '';
        $this->line('[成功]'.$code.' '.(string) ($result['message'] ?? ''));
        if (($result['run_id'] ?? null) !== null) {
            $this->line('run_id: '.(string) $result['run_id']);
        }
        $this->renderData($result);
    }

    protected function renderFailure(MasterDeployRunException $failure): void
    {
        $this->line("[失敗] {$failure->errorCode}: {$failure->getMessage()}");
        if ($failure->runId !== null) {
            $this->line("run_id: {$failure->runId}");
        }
        if ($failure->errorCode === MasterDeployRunException::REGISTER_UNKNOWN && $failure->runId !== null) {
            // sys の COMMIT の成否が不明で、DB を DROP していない。人が確認する（api.md 13.1）
            $this->line("sys の登録の成否が不明です。DB は削除していません。master:resolve-unknown {$failure->runId} で片付けてください。");
        }
        if ($failure->errorList !== []) {
            $this->line('error_list:');
            $this->renderTable($failure->errorList);
        }
        $this->renderData($failure->extra);
    }

    /**
     * 結果の配列を、スカラーは `key: value`、レコードの配列は表で出す（入れ子は `親.子` で辿る）
     *
     * @param  array<string, mixed>  $data
     */
    protected function renderData(array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            if ($prefix === '' && in_array($key, self::HEADER_KEYS, true)) {
                continue;
            }
            $label = $prefix.$key;
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                if (array_is_list($value)) {
                    if (is_array($value[0])) {
                        $this->line("{$label}:");
                        $this->renderTable($value);
                    } else {
                        $this->line("{$label}: ".implode(', ', array_map(fn ($v): string => $this->format($key, $v), $value)));
                    }
                } elseif ($key === 'tables') {
                    // 表ごとのファイル情報は長いので、表の数だけを出す
                    $this->line("{$label}: ".count($value).' 表');
                } else {
                    $this->renderData($value, $label.'.');
                }

                continue;
            }
            $this->line("{$label}: ".$this->format((string) $key, $value));
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function renderTable(array $rows): void
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $column) {
                $columns[(string) $column] = true;
            }
        }
        $columns = array_keys($columns);
        $body = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->format($column, is_array($row) ? ($row[$column] ?? null) : null);
            }
            $body[] = $line;
        }
        $this->table($columns, $body);
    }

    /**
     * 値を表示用の文字列にする。UTC の日時には JST を併記する
     */
    private function format(string $key, mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $text = (string) $value;
        if (($key === 'now' || str_ends_with($key, '_at')) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $text) === 1) {
            return $text.' UTC ('.Carbon::createFromFormat('Y-m-d H:i:s', $text, 'UTC')->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s').' JST)';
        }

        return $text;
    }

    // ---- 入力の検査（形式の誤りは終了コード 2） ----

    /**
     * 正の整数の引数。形式が違えばメッセージを出して null を返す（呼び出し側は EXIT_INPUT を返す）
     */
    protected function positiveInt(string $argument): ?int
    {
        $value = (string) $this->argument($argument);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            $this->line("[失敗] invalid_input: {$argument} は 1 以上の整数で指定してください（指定: {$value}）。");

            return null;
        }

        return (int) $value;
    }

    /**
     * `Y-m-d H:i:s`（UTC）の検査。不正なら false
     */
    protected function isDateTime(string $value): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC')->format('Y-m-d H:i:s') === $value;
        } catch (InvalidFormatException) {
            return false;
        }
    }

    /**
     * --start-at の値（`$this->option('start-at')`）を検査して返す。未指定は null（「すぐ」）。形式が違えば false
     */
    protected function parseStartAt(mixed $value, bool $allowNow): string|false|null
    {
        if (! is_string($value) || $value === '' || ($allowNow && $value === 'now')) {
            return null;
        }
        if (! $this->isDateTime($value)) {
            $this->line('[失敗] invalid_input: --start-at は "Y-m-d H:i:s"（UTC）で指定してください'.($allowNow ? '（now で「すぐ」）' : '').'。');

            return false;
        }

        return $value;
    }
}
