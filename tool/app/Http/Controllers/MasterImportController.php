<?php

namespace App\Http\Controllers;

use App\Exceptions\MasterDeployRunException;
use App\Http\Requests\MasterImport\ArtifactListRequest;
use App\Http\Requests\MasterImport\BuildRequest;
use App\Http\Requests\MasterImport\CleanupRequest;
use App\Http\Requests\MasterImport\FinalizeRequest;
use App\Http\Requests\MasterImport\ImportRequest;
use App\Http\Requests\MasterImport\ResolveUnknownRequest;
use App\Http\Requests\MasterImport\RollbackRequest;
use App\Http\Requests\MasterImport\SysDeployIdRequest;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterActivationService;
use App\Services\MasterBuildRunner;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterDeployTargetService;
use App\Services\MasterFinalizeRunner;
use App\Services\MasterImportRunner;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterRollbackService;
use App\Support\MasterDeployFailure;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * MasterImportController
 *
 * マスターデータの build・import・QA 完了・巻き戻し・予約取消・緊急停止・掃除（版ごとの mst DB 設計。ADR 0007）。
 * 各操作は Service / Runner を呼ぶだけの薄い層で、失敗は MasterDeployRunException を api.md 13.1 の code・HTTP ステータスで返す。
 * 設計: docs/design/master-distribution/api.md 2〜9・13 節
 *
 * エンドポイント:
 * GET  /master-import                    画面（props に target を含む）
 * GET  /master-import/target             対象・版の一覧・予約・警告 (JSON)
 * GET  /master-import/artifact-list      成果物の一覧 (JSON)
 * POST /master-import/build              build（dry_run 可）
 * POST /master-import/import             import（dry_run 可）
 * POST /master-import/finalize           QA 完了（dry_run 可）
 * POST /master-import/rollback           巻き戻し（dry_run 可）
 * POST /master-import/cancel-schedule    予約の取り消し
 * POST /master-import/cleanup            掃除（既定は dry_run）
 * POST /master-import/clear-cache        schedule キーなどの破棄
 * POST /master-import/resolve-unknown    register_unknown の片付け
 * POST /master-import/deactivate         緊急停止（is_active を落とす）
 * POST /master-import/activate           再開
 * GET  /master-import/all-sheets・spreadsheets・sheets・preview   シートの一覧・内容の確認（build の環境だけ）
 */
class MasterImportController extends Controller
{
    private const CACHE_FILE = 'master-import/sheet-list-cache.json';

    private ?GoogleSpreadsheetService $sheetService = null;

    /**
     * 認証情報が無いと作れないので、使うときに解決する（build 以外の操作・target は未設定でも動く）
     */
    private function spreadsheetService(): GoogleSpreadsheetService
    {
        // Client と OAuth トークンを作り直さないよう、リクエスト内で使い回す
        return $this->sheetService ??= app(GoogleSpreadsheetService::class);
    }

    /**
     * 画面を表示する
     *
     * キャッシュファイルが存在する場合は初期データとして渡す。
     */
    public function index(Request $request, MasterDeployTargetService $targetService): Response
    {
        $cachedSheets = null;
        $cachedAt = null;
        $configured = GoogleSpreadsheetService::checkConfigured();

        if (Storage::exists(self::CACHE_FILE)) {
            $raw = Storage::get(self::CACHE_FILE);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cachedSheets = $decoded['sheets'] ?? null;
                $cachedAt = $decoded['cached_at'] ?? null;
            }
        }

        // 対象の組み立て（DB 読み取り）に失敗しても画面ごと落とさない。画面は target=null で対象を出さない
        try {
            $target = $targetService->build($configured);
        } catch (Throwable $e) {
            report($e);
            $target = null;
        }

        return Inertia::render('MasterImport/Index', [
            'auth' => ['user' => $request->user()],
            'is_configured' => $configured,
            'folder_id' => config('services.google_spreadsheet.folder_id'),
            'cached_sheets' => $cachedSheets,
            'cached_at' => $cachedAt,
            'target' => $target,
        ]);
    }

    /**
     * 対象・版の一覧・予約・警告を返す（DB と設定を読むだけ。シートは読まない。ロックは取らない）
     *
     * 設計: api.md 4 節
     */
    public function target(MasterDeployTargetService $targetService): JsonResponse
    {
        return $this->respond(fn (): array => $targetService->build(GoogleSpreadsheetService::checkConfigured()));
    }

    /**
     * 成果物の一覧（import・finalize の入力の選択肢。ストレージを読むだけ）
     *
     * 設計: api.md 5 節
     */
    public function artifactList(ArtifactListRequest $request, MasterDeployTargetService $targetService): JsonResponse
    {
        return $this->respond(fn (): array => [
            'status' => 'success',
            'artifact_list' => $targetService->artifactList($request->source()),
        ]);
    }

    /**
     * build（シート → 成果物と版の DB → sys に登録。dry_run なら検証だけ）
     *
     * 設計: api.md 6 節
     */
    public function build(BuildRequest $request, MasterBuildRunner $runner): JsonResponse
    {
        return $this->respondLong(fn (): array => $runner->run([
            'dry_run' => $request->dryRun(),
            'start_at' => $request->startAt(),
            'adm_account_id' => $this->admAccountId($request),
        ]));
    }

    /**
     * import（上流の成果物 → 版の DB → sys に登録。dry_run なら検査だけ）
     *
     * 設計: api.md 7 節
     */
    public function import(ImportRequest $request, MasterImportRunner $runner): JsonResponse
    {
        return $this->respondLong(fn (): array => $runner->run($request->artifactDir(), [
            'dry_run' => $request->dryRun(),
            'start_at' => $request->startAt(),
            'adm_account_id' => $this->admAccountId($request),
        ]));
    }

    /**
     * QA 完了（追従検出 → final.json）
     *
     * 設計: api.md 8 節
     */
    public function finalize(FinalizeRequest $request, MasterFinalizeRunner $runner): JsonResponse
    {
        return $this->respondLong(fn (): array => $runner->run($request->artifactDir(), [
            'dry_run' => $request->dryRun(),
            'allow_unverified' => $request->allowUnverified(),
            'adm_account_id' => $this->admAccountId($request),
        ]));
    }

    /**
     * 巻き戻し（戻したい版を指す新しい sys_deploy 行を足す）
     *
     * 設計: api.md 9.1
     */
    public function rollback(RollbackRequest $request, MasterRollbackService $service): JsonResponse
    {
        return $this->respondLong(fn (): array => $service->run($request->sysDeployMasterId(), [
            'dry_run' => $request->dryRun(),
            'adm_account_id' => $this->admAccountId($request),
        ]));
    }

    /**
     * 予約の取り消し（到達していない sys_deploy 行を削除する）
     *
     * 設計: api.md 9.2
     */
    public function cancelSchedule(SysDeployIdRequest $request, MasterCancelScheduleService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->run($request->sysDeployId(), ['adm_account_id' => $this->admAccountId($request)]));
    }

    /**
     * 掃除（版の DB・孤児・一時の場所。既定は dry_run）
     *
     * 設計: api.md 9.3
     */
    public function cleanup(CleanupRequest $request, MasterCleanupService $service): JsonResponse
    {
        return $this->respondLong(fn (): array => $service->run([
            'dry_run' => $request->dryRun(),
            'adm_account_id' => $this->admAccountId($request),
        ]));
    }

    /**
     * schedule キー（と id・deploy_key・tables・旧キー）を破棄する。ロックは取らない・冪等
     *
     * 設計: api.md 9.4。mst のキャッシュは版つきキーなので破棄しない。
     */
    public function clearCache(MasterCacheInvalidator $invalidator): JsonResponse
    {
        $message = 'キャッシュの破棄に失敗しました。Redis の接続を確認して、もう一度実行してください。';
        try {
            $cache = $invalidator->forgetScheduleAndRelated();
        } catch (Throwable $e) {
            // 破棄するキーを調べる段階（DB）の障害。forget の失敗とは文言を分ける
            report($e);
            $cache = ['invalidated' => false, 'failed_key_list' => []];
            $message = '破棄するキーを調べられませんでした（DB または Redis の障害）。ログを確認して、もう一度実行してください。';
        }

        if (! $cache['invalidated']) {
            return response()->json([
                'status' => 'error',
                'code' => 'cache_clear_failed',
                'message' => $message,
                'cache' => $cache,
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'キャッシュを破棄しました。',
            'cache' => $cache,
        ]);
    }

    /**
     * register_unknown の run を、sys を再確認して片付ける
     *
     * 設計: api.md 9.5
     */
    public function resolveUnknown(ResolveUnknownRequest $request, MasterResolveUnknownService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->run($request->runId(), ['adm_account_id' => $this->admAccountId($request)]));
    }

    /**
     * 緊急停止（sys_deploy.is_active を落とす）
     *
     * 設計: api.md 9.6
     */
    public function deactivate(SysDeployIdRequest $request, MasterActivationService $service): JsonResponse
    {
        return $this->respondLong(fn (): array => $service->deactivate($request->sysDeployId(), ['adm_account_id' => $this->admAccountId($request)]));
    }

    /**
     * 再開（sys_deploy.is_active を戻す。掃除済みの版は成果物から復元する）
     *
     * 設計: api.md 9.6
     */
    public function activate(SysDeployIdRequest $request, MasterActivationService $service): JsonResponse
    {
        return $this->respondLong(fn (): array => $service->activate($request->sysDeployId(), ['adm_account_id' => $this->admAccountId($request)]));
    }

    /**
     * フォルダ内の全スプレッドシートの全シートを一括取得してキャッシュに保存する
     *
     * 返却形式:
     * { status, sheets: [{ spreadsheet_id, spreadsheet_name, sheet_title, is_mst }], cached_at }
     */
    public function allSheets(): JsonResponse
    {
        try {
            $this->assertSheetRole();
            $spreadsheets = $this->spreadsheetService()->listSpreadsheets();
            $allSheets = [];

            foreach ($spreadsheets as $ss) {
                $sheets = $this->spreadsheetService()->listSheets($ss['id']);
                foreach ($sheets as $sheet) {
                    $allSheets[] = [
                        'spreadsheet_id' => $ss['id'],
                        'spreadsheet_name' => $ss['name'],
                        'sheet_title' => $sheet['title'],
                        'is_mst' => str_starts_with($sheet['title'], 'mst_'),
                    ];
                }
            }

            $cachedAt = now()->format('Y-m-d H:i:s');

            // キャッシュファイルに保存
            Storage::put(self::CACHE_FILE, json_encode([
                'sheets' => $allSheets,
                'cached_at' => $cachedAt,
            ], JSON_UNESCAPED_UNICODE));

            return response()->json([
                'status' => 'success',
                'sheets' => $allSheets,
                'cached_at' => $cachedAt,
            ]);
        } catch (Throwable $e) {
            return $this->sheetErrorResponse($e);
        }
    }

    /**
     * フォルダ内のスプレッドシート一覧を取得する
     */
    public function spreadsheets(): JsonResponse
    {
        try {
            $this->assertSheetRole();
            $spreadsheets = $this->spreadsheetService()->listSpreadsheets();

            return response()->json([
                'status' => 'success',
                'spreadsheets' => $spreadsheets,
            ]);
        } catch (Throwable $e) {
            return $this->sheetErrorResponse($e);
        }
    }

    /**
     * スプレッドシートのシート一覧を取得する
     */
    public function sheets(Request $request): JsonResponse
    {
        $request->validate([
            'spreadsheet_id' => ['required', 'string'],
        ]);

        try {
            $this->assertSheetRole();
            $sheets = $this->spreadsheetService()->listSheets(
                $request->input('spreadsheet_id'),
            );

            return response()->json([
                'status' => 'success',
                'sheets' => $sheets,
            ]);
        } catch (Throwable $e) {
            return $this->sheetErrorResponse($e);
        }
    }

    /**
     * インポート前プレビューデータを取得する
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'spreadsheet_id' => ['required', 'string'],
            'sheet_title' => ['required', 'string'],
        ]);

        try {
            $this->assertSheetRole();
            $preview = $this->spreadsheetService()->getPreviewData(
                $request->input('spreadsheet_id'),
                $request->input('sheet_title'),
            );

            return response()->json([
                'status' => 'success',
                'preview' => $preview, // テーブル名をキーとした連想配列
            ]);
        } catch (Throwable $e) {
            return $this->sheetErrorResponse($e);
        }
    }

    /**
     * シートを読む操作は build の環境だけ（import の環境には シートが無い。api.md 2 節の role の表）
     *
     * @throws MasterDeployRunException role_forbidden
     */
    private function assertSheetRole(): void
    {
        if (config('master_deploy.role') !== 'build') {
            throw new MasterDeployRunException(
                MasterDeployRunException::ROLE_FORBIDDEN,
                'この環境（role='.(string) config('master_deploy.role').'）ではシートを読む操作はできません（build の環境だけ）。',
                403,
            );
        }
    }

    /**
     * 操作を実行し、MasterDeployRunException を code・HTTP ステータスつきの JSON にする（api.md 2・13 節）
     *
     * @param  Closure(): array<string, mixed>  $operation
     */
    private function respond(Closure $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (Throwable $e) {
            $failure = MasterDeployFailure::from($e);

            return response()->json($failure->toArray(), $failure->httpStatus);
        }
    }

    /**
     * 時間のかかる操作（シートの全タブ・成果物の pull と投入・復元・掃除）。時間制限を外し、ブラウザが切れても止めない（api.md 11.2）
     *
     * @param  Closure(): array<string, mixed>  $operation
     */
    private function respondLong(Closure $operation): JsonResponse
    {
        set_time_limit(0);
        // ロックを持った処理を途中で止めない
        ignore_user_abort(true);

        return $this->respond($operation);
    }

    private function admAccountId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * シート系のエラーレスポンス（role_forbidden は code・HTTP 403 つき。それ以外は 422 の固定文言で、例外の文言は出さない）
     */
    private function sheetErrorResponse(Throwable $e): JsonResponse
    {
        if ($e instanceof MasterDeployRunException) {
            return response()->json($e->toArray(), $e->httpStatus);
        }

        // Google 側・設定の想定外の例外の文言（認証情報・内部の詳細）は画面に出さず、ログに残す
        report($e);

        return response()->json([
            'status' => 'error',
            'message' => 'シートの読み込みに失敗しました。認証情報・フォルダの設定とログを確認してください。',
        ], 422);
    }
}
