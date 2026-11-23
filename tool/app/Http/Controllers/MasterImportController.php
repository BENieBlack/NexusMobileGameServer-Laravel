<?php

namespace App\Http\Controllers;

use App\Exceptions\MasterDeployRunException;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDataExporter;
use App\Services\MasterDeployRunner;
use App\Services\MasterDeployService;
use App\Services\MasterDeployTargetService;
use App\Services\MasterImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * MasterImportController
 *
 * Google スプレッドシートからマスターデータをインポートする。
 *
 * エンドポイント:
 * GET  /master-import                    インポート画面（キャッシュがあれば初期表示。props に target を含む）
 * GET  /master-import/target             デプロイの対象・状態 (JSON)
 * POST /master-import/deploy             デプロイ（dry_run 可） (JSON)
 * POST /master-import/clear-cache        API のキャッシュ破棄 (JSON)
 * GET  /master-import/all-sheets         全スプレッドシートの全シート一括取得・キャッシュ保存 (JSON)
 * GET  /master-import/spreadsheets       スプレッドシート一覧取得 (JSON)
 * GET  /master-import/sheets             シート一覧取得 (JSON)
 * GET  /master-import/preview            プレビューデータ取得 (JSON)
 * POST /master-import/execute            インポート実行 (JSON)
 * POST /master-import/export             SQLiteエクスポート＆sys_deploy登録 (JSON)
 */
class MasterImportController extends Controller
{
    private const CACHE_FILE = 'master-import/sheet-list-cache.json';

    private ?GoogleSpreadsheetService $sheetService = null;

    public function __construct(
        private readonly MasterImportService $importService,
        private readonly MasterDataExporter $exporter,
        private readonly MasterDeployService $deployService,
    ) {}

    /**
     * 認証情報が無いと作れないので、使うときに解決する（deploy・target・clear-cache は未設定でも動く）
     */
    private function spreadsheetService(): GoogleSpreadsheetService
    {
        // Client と OAuth トークンを作り直さないよう、リクエスト内で使い回す
        return $this->sheetService ??= app(GoogleSpreadsheetService::class);
    }

    /**
     * インポート画面を表示する
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
     * デプロイの対象・状態を返す（DB と設定を読むだけ。シートは読まない）
     *
     * 設計: docs/design/master-distribution/api.md 3 節
     */
    public function target(MasterDeployTargetService $targetService): JsonResponse
    {
        return response()->json($targetService->build(GoogleSpreadsheetService::checkConfigured()));
    }

    /**
     * デプロイを 1 回行う（dry_run なら検証だけ）
     *
     * 設計: docs/design/master-distribution/api.md 4 節。失敗は MasterDeployRunException の toArray() と httpStatus で返す。
     */
    public function deploy(Request $request, MasterDeployRunner $runner): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        // シートの全タブの読み込みが長引くため時間制限を外す（api.md 11.2 の既定案。nginx 側は S3 で実測）
        set_time_limit(0);
        // ブラウザが切れても、ロックを持った処理を途中で止めない
        ignore_user_abort(true);

        try {
            $result = $runner->run([
                'dry_run' => (bool) ($validated['dry_run'] ?? false),
                'adm_account_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return response()->json($result);
        } catch (MasterDeployRunException $e) {
            return response()->json($e->toArray(), $e->httpStatus);
        } catch (Throwable $e) {
            // runDry の準備段階の DB 例外など。詳細（SQL など）は画面に出さずログに残す（api.md 4 節 internal_error）
            report($e);

            return response()->json([
                'status' => 'error',
                'code' => MasterDeployRunException::INTERNAL_ERROR,
                'message' => '想定外のエラーが発生しました。ログを確認してください。',
                'error_list' => [],
                'run_id' => null,
            ], 500);
        }
    }

    /**
     * API のキャッシュ（sys_deploy の 3 種と mst の全表）を破棄する。ロックは取らない・冪等
     *
     * 設計: docs/design/master-distribution/api.md 5 節
     */
    public function clearCache(MasterCacheInvalidator $invalidator): JsonResponse
    {
        $message = 'キャッシュの破棄に失敗しました。Redis の接続を確認して、もう一度実行してください。';
        try {
            $cache = $invalidator->invalidateAll();
        } catch (Throwable $e) {
            // 破棄するキーを調べる段階（DB）や接続の障害。forget の失敗とは文言を分ける
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
     * フォルダ内の全スプレッドシートの全シートを一括取得してキャッシュに保存する
     *
     * 返却形式:
     * { status, sheets: [{ spreadsheet_id, spreadsheet_name, sheet_title, is_mst }], cached_at }
     */
    public function allSheets(): JsonResponse
    {
        try {
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
            return $this->errorResponse($e);
        }
    }

    /**
     * フォルダ内のスプレッドシート一覧を取得する
     */
    public function spreadsheets(): JsonResponse
    {
        try {
            $spreadsheets = $this->spreadsheetService()->listSpreadsheets();

            return response()->json([
                'status' => 'success',
                'spreadsheets' => $spreadsheets,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
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
            $sheets = $this->spreadsheetService()->listSheets(
                $request->input('spreadsheet_id'),
            );

            return response()->json([
                'status' => 'success',
                'sheets' => $sheets,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
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
            $preview = $this->spreadsheetService()->getPreviewData(
                $request->input('spreadsheet_id'),
                $request->input('sheet_title'),
            );

            return response()->json([
                'status' => 'success',
                'preview' => $preview, // テーブル名をキーとした連想配列
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * インポートを実行する
     *
     * シート内の全テーブルをインポートする。
     * 1行目のテーブル名ごとに対応するmstテーブルへ書き込む。
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'spreadsheet_id' => ['required', 'string'],
            'sheet_title' => ['required', 'string'],
        ]);

        $spreadsheetId = $request->input('spreadsheet_id');
        $sheetTitle = $request->input('sheet_title');

        try {
            // スプレッドシートからデータ取得（テーブルごとにグループ化済み）
            $tableData = $this->spreadsheetService()->getSheetData($spreadsheetId, $sheetTitle);

            if (empty($tableData)) {
                return response()->json([
                    'status' => 'warning',
                    'message' => "シート「{$sheetTitle}」にデータが存在しません。",
                    'results' => [],
                ]);
            }

            $results = [];
            foreach ($tableData as $tableName => $data) {
                if (empty($data['rows'])) {
                    $results[] = [
                        'table' => $tableName,
                        'inserted' => 0,
                        'skipped' => 0,
                        'errors' => ['データ行がありません'],
                    ];

                    continue;
                }

                $results[] = $this->importService->import(
                    $tableName,
                    $data['headers'],
                    $data['rows'],
                );
            }

            $totalInserted = array_sum(array_column($results, 'inserted'));
            $tableNames = implode(', ', array_column($results, 'table'));

            return response()->json([
                'status' => 'success',
                'message' => "「{$tableNames}」へ合計 {$totalInserted} 件インポートしました。",
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * エラーレスポンスを生成する
     */
    private function errorResponse(Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 422);
    }

    /**
     * mstデータベースをSQLiteにエクスポートし sys_deploy に登録する
     *
     * 処理フロー:
     * 1. mst全テーブルをSQLiteファイルに変換
     * 2. デプロイ単位のハッシュディレクトリへテーブル別SQLiteを配置
     * 3. sys_deploy_master / sys_deploy_asset / sys_deploy に登録
     */
    public function export(): JsonResponse
    {
        try {
            // SQLiteエクスポート
            $exportResult = $this->exporter->export();

            // sys_deploy 系テーブルに登録
            $deployResult = $this->deployService->register($exportResult);

            return response()->json([
                'status' => 'success',
                'message' => $deployResult['is_new']
                    ? "SQLiteを生成し sys_deploy (deploy_key={$deployResult['deploy_key']}) に登録しました。"
                    : "同一ハッシュのデプロイが既に登録されています (deploy_key={$deployResult['deploy_key']})。",
                'export' => [
                    'hash' => $exportResult['hash'],
                    'file_size' => $exportResult['file_size'],
                    'table_count' => $exportResult['table_count'],
                    'file_count' => $exportResult['file_count'],
                    'tables' => $exportResult['tables'],
                ],
                'deploy' => [
                    'deploy_key' => $deployResult['deploy_key'],
                    'sys_deploy_master_id' => $deployResult['sys_deploy_master_id'],
                    'sys_deploy_id' => $deployResult['sys_deploy_id'],
                    'is_new' => $deployResult['is_new'],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
