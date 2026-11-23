<?php

namespace App\Http\Middleware;

use App\Persistence\CurrentMasterVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveMasterVersion
 *
 * リクエストの冒頭で「いまの版」を固定し、mst 接続の database をその版の mst DB に切り替える。
 * 設計: docs/design/master-distribution/db.md 8.1
 *
 * - 順序は maintenance → platform → master.version → 署名・認証。mst に触れるのは認証より後なので、
 *   その前に切り替えを済ませる。
 * - いまの版が無い・版の DB が掃除済みのときは GameException（SystemDataException::deploy）を投げ、
 *   bootstrap/app.php のハンドラが HTTP 299 にする。基底 DB にはフォールバックしない。
 * - 付けるのは mst を読むゲーム API のグループだけ（routes/api.php）。メンテ状態・admin・
 *   GET /auth/version・broadcasting は mst を読まないので付けない（版が無くても動かす）。
 */
class ResolveMasterVersion
{
    public function __construct(
        private readonly CurrentMasterVersion $currentMasterVersion,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->currentMasterVersion->applyMstConnection();

        return $next($request);
    }
}
