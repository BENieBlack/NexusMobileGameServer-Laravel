<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LogAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // log_* はプレイヤーと同じシャード DB にあり、ログ専用の接続は無い。
        // 他の Log 系シーダーと同じく、シャード 1 の別名接続 trx を使う
        $connection = 'trx';

        // 既存のデータを削除
        DB::connection($connection)->table('log_action_api_access')->truncate();

        $now = now();
        // 既定は 1 年前から開始。テストなど重くしたくない場合は seeders.log_access_days で日数を絞る
        $days = (int) config('seeders.log_access_days', 365);
        $startDate = $now->copy()->subDays($days);

        $endpoints = [
            '/api/auth/login',
            '/api/auth/signup',
            '/api/player/profile',
            '/api/gacha/draw',
            '/api/item/list',
            '/api/quest/start',
            '/api/quest/complete',
            '/api/shop/purchase',
            '/api/friend/list',
            '/api/ranking/view',
        ];

        $methods = ['GET', 'POST', 'PUT'];

        $data = [];
        $current = $startDate->copy();

        // 進捗は 1 行の上書きで出す（行を増やさず、同じ位置の数字だけ書き換える。
        // バッチごとに改行すると、365 日分で数百行流れてしまうため）。1 日ぶん進むごとに更新する
        echo "仮データを生成中（{$days}日分）...\n";
        $lastReportedDay = 0;

        // 1年分のデータを10分間隔で生成（データ量を調整）
        while ($current <= $now) {
            // 時間帯によってアクセス数を変動させる
            $hour = (int) $current->format('H');

            // アクセスパターン: 朝(6-9時)、昼(12-14時)、夜(20-23時)にピーク
            if ($hour >= 6 && $hour < 9) {
                $baseCount = rand(15, 30); // 朝のピーク
            } elseif ($hour >= 12 && $hour < 14) {
                $baseCount = rand(20, 35); // 昼のピーク
            } elseif ($hour >= 20 && $hour < 23) {
                $baseCount = rand(25, 40); // 夜のピーク（最大）
            } elseif ($hour >= 0 && $hour < 5) {
                $baseCount = rand(2, 8); // 深夜は少ない
            } else {
                $baseCount = rand(8, 20); // 通常時間
            }

            // ランダムな変動を加える
            $count = $baseCount + rand(-3, 3);
            $count = max(1, $count); // 最小1件

            // その時間帯のアクセスログを生成
            for ($i = 0; $i < $count; $i++) {
                $endpoint = $endpoints[array_rand($endpoints)];
                $method = $methods[array_rand($methods)];

                // ランダムなプレイヤーID（1-1000）
                $playerId = rand(1, 1000);

                // 成功率90%
                $statusCode = rand(1, 10) <= 9 ? 200 : (rand(0, 1) ? 400 : 500);

                $data[] = [
                    'unique_request_id' => Str::uuid()->toString(),
                    'sys_player_id' => $playerId,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'request_header' => json_encode([
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'MobileGame/1.0',
                    ]),
                    'request_body' => json_encode(['data' => 'sample']),
                    'response_header' => json_encode([
                        'Content-Type' => 'application/json',
                    ]),
                    'response_body' => json_encode(['status' => 'success']),
                    'status_code' => $statusCode,
                    'system_at' => $current->format('Y-m-d H:i:s'),
                    'created_at' => $current->format('Y-m-d H:i:s'),
                ];
            }

            // 10分進める
            $current->addMinutes(10);

            // 大量データをバッチで挿入（1000件ごと）
            if (count($data) >= 1000) {
                DB::connection($connection)->table('log_action_api_access')->insert($data);
                $data = [];
            }

            // 何日分まで進んだか（開始日からの経過日数）。日が進んだときだけ表示を更新する
            $doneDays = min($days, (int) $startDate->diffInDays($current));
            if ($doneDays > $lastReportedDay) {
                $lastReportedDay = $doneDays;
                echo "\r  {$doneDays}/{$days}日分完了";
            }
        }

        // 残りのデータを挿入
        if (! empty($data)) {
            DB::connection($connection)->table('log_action_api_access')->insert($data);
        }
        // 進捗の行を閉じる（上書きしていた行の末尾に改行を入れる）
        echo "\r  {$days}/{$days}日分完了\n";

        $totalCount = DB::connection($connection)->table('log_action_api_access')->count();
        echo "\n完了: 合計 {$totalCount} 件のログデータを生成しました。\n";
    }
}
