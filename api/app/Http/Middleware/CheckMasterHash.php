<?php

namespace App\Http\Middleware;

use App\Persistence\CurrentMasterVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * クライアントのマスターハッシュをいまの版（CurrentMasterVersion が固定したもの）と比較する。
 * 不一致時もAPI処理は継続し、レスポンスヘッダーで更新要求を通知する。
 */
class CheckMasterHash
{
    public function __construct(
        private readonly CurrentMasterVersion $currentMasterVersion,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $clientHash = $request->header('X-Master-Hash');

        if ($clientHash === null || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            // $next の後で引き直さず、リクエスト冒頭で固定した版を使う。
            // 引き直すと、途中で start_at を過ぎたときに応答の版と比較対象の版がずれる（db.md 8.1）
            $sysDeploy = $this->currentMasterVersion->current();
            $masterHash = $sysDeploy?->deployMaster?->getHash();

            if ($masterHash === null || hash_equals((string) $masterHash, $clientHash)) {
                return $response;
            }

            // 更新情報本体は /auth/version で取得するため、通常APIのBodyは変更しない。
            $response->headers->set('X-Master-Update-Required', 'true');
        } catch (Throwable) {
            // マスターハッシュ確認の失敗で本来のAPIレスポンスを壊さない。
        }

        return $response;
    }
}
