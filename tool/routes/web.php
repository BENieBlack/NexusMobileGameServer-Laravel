<?php

use App\Http\Controllers\AdmManagementController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterImportController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerHistoryController;
use Illuminate\Support\Facades\Route;

// ゲストユーザー用ルート（未ログイン）
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// 認証済みユーザー用ルート
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect('/dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/access-status', [DashboardController::class, 'accessStatus'])->name('dashboard.access-status');
    Route::get('/dashboard/retention-status', [DashboardController::class, 'retentionStatus'])->name('dashboard.retention-status');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/player/list', [PlayerController::class, 'list'])->name('player.list');
    Route::get('/player/index', [PlayerController::class, 'index'])->name('player.index');
    Route::get('/player/log', [PlayerHistoryController::class, 'index'])->name('player.log');

    Route::prefix('adm')->name('adm.')->group(function () {
        Route::get('/accounts', [AdmManagementController::class, 'accounts'])->name('accounts.index');
        Route::get('/accounts/create', [AdmManagementController::class, 'createAccount'])->name('accounts.create');
        Route::post('/accounts', [AdmManagementController::class, 'storeAccount'])->name('accounts.store');
        Route::get('/accounts/{account}/edit', [AdmManagementController::class, 'editAccount'])->name('accounts.edit');
        Route::put('/accounts/{account}', [AdmManagementController::class, 'updateAccount'])->name('accounts.update');
        Route::get('/roles', [AdmManagementController::class, 'roles'])->name('roles.index');
        Route::get('/roles/create', [AdmManagementController::class, 'createRole'])->name('roles.create');
        Route::post('/roles', [AdmManagementController::class, 'storeRole'])->name('roles.store');
        Route::get('/roles/{role}/edit', [AdmManagementController::class, 'editRole'])->name('roles.edit');
        Route::put('/roles/{role}', [AdmManagementController::class, 'updateRole'])->name('roles.update');
        Route::get('/pages', [AdmManagementController::class, 'pages'])->name('pages.index');
        Route::get('/pages/create', [AdmManagementController::class, 'createPage'])->name('pages.create');
        Route::post('/pages', [AdmManagementController::class, 'storePage'])->name('pages.store');
        Route::get('/pages/{page}/edit', [AdmManagementController::class, 'editPage'])->name('pages.edit');
        Route::put('/pages/{page}', [AdmManagementController::class, 'updatePage'])->name('pages.update');
    });

    // マスターデータインポート
    Route::prefix('master-import')->name('master-import.')->group(function () {
        Route::get('/', [MasterImportController::class, 'index'])->name('index');
        Route::get('/all-sheets', [MasterImportController::class, 'allSheets'])->name('all-sheets');
        Route::get('/spreadsheets', [MasterImportController::class, 'spreadsheets'])->name('spreadsheets');
        Route::get('/sheets', [MasterImportController::class, 'sheets'])->name('sheets');
        Route::get('/preview', [MasterImportController::class, 'preview'])->name('preview');
        Route::get('/target', [MasterImportController::class, 'target'])->name('target');
        Route::get('/artifact-list', [MasterImportController::class, 'artifactList'])->name('artifact-list');
        Route::post('/build', [MasterImportController::class, 'build'])->name('build');
        Route::post('/import', [MasterImportController::class, 'import'])->name('import');
        Route::post('/finalize', [MasterImportController::class, 'finalize'])->name('finalize');
        Route::post('/rollback', [MasterImportController::class, 'rollback'])->name('rollback');
        Route::post('/cancel-schedule', [MasterImportController::class, 'cancelSchedule'])->name('cancel-schedule');
        Route::post('/cleanup', [MasterImportController::class, 'cleanup'])->name('cleanup');
        Route::post('/clear-cache', [MasterImportController::class, 'clearCache'])->name('clear-cache');
        Route::post('/resolve-unknown', [MasterImportController::class, 'resolveUnknown'])->name('resolve-unknown');
        Route::post('/deactivate', [MasterImportController::class, 'deactivate'])->name('deactivate');
        Route::post('/activate', [MasterImportController::class, 'activate'])->name('activate');
        // 旧 deploy・execute・export は廃止（404。S3-16）
    });
});
