<template>
    <DashboardLayout :auth="auth">
        <div class="p-8 space-y-6">
            <!-- 見出し -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-800">マスターデータ配信</h1>
                <a
                    v-if="folder_id && role === 'build'"
                    :href="`https://drive.google.com/drive/folders/${folder_id}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 hover:text-gray-800 transition-colors shadow-sm"
                >
                    Google Drive を開く
                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>
            </div>

            <!-- 対象を読めなかった -->
            <div v-if="!target" class="bg-red-50 border border-red-300 rounded-lg p-4 flex items-center justify-between">
                <p class="text-sm text-red-800">対象の情報（target）を読み込めませんでした。DB・設定を確認して、再取得してください。</p>
                <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700" :disabled="loadingTarget" @click="loadTarget()">再取得</button>
            </div>

            <template v-else>
                <!-- ================= 対象の表示 ================= -->
                <section class="bg-white rounded-lg shadow p-6 space-y-4" data-testid="target-panel">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-700">対象</h2>
                        <div class="flex items-center gap-3">
                            <span v-if="target.running" class="px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800 rounded-full">他の操作が実行中</span>
                            <span class="text-xs text-gray-400">サーバー時刻（読み込み時点） <DateBoth :value="target.now" /></span>
                            <button class="px-3 py-1.5 text-xs text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50" :disabled="loadingTarget" @click="loadTarget()">
                                {{ loadingTarget ? '取得中...' : '状態を再取得' }}
                            </button>
                        </div>
                    </div>

                    <!-- blocked -->
                    <div v-if="target.blocked" class="bg-red-50 border border-red-300 rounded-lg p-3 text-sm text-red-800">
                        <p class="font-semibold">いま build・import はできません（<code>{{ target.blocked.code }}</code>）</p>
                        <p class="mt-1">{{ target.blocked.message }}</p>
                        <p class="mt-1 text-xs text-red-600">巻き戻し・予約の取り消し・緊急停止・再開は使えます。</p>
                    </div>

                    <dl class="grid grid-cols-[9rem_1fr] md:grid-cols-[9rem_1fr_9rem_1fr] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-gray-500">環境</dt>
                        <dd class="font-semibold" :class="target.app_env === 'production' ? 'text-red-700' : 'text-gray-800'">{{ target.app_env }}</dd>
                        <dt class="text-gray-500">role</dt>
                        <dd>
                            <span :class="['px-2 py-0.5 text-xs font-semibold rounded-full', role === 'build' ? 'bg-indigo-100 text-indigo-700' : role === 'import' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700']">{{ target.role || '未設定' }}</span>
                        </dd>
                        <template v-if="role === 'build'">
                            <dt class="text-gray-500">T（畳みの上限）</dt>
                            <dd class="font-mono">{{ target.deploy_key ?? '—' }} <span class="text-xs text-gray-400 font-sans">（{{ target.mode === 'fixed' ? '固定キー' : target.mode === 'explicit' ? '明示キー' : '—' }}）</span></dd>
                        </template>
                        <dt class="text-gray-500">基底 DB</dt>
                        <dd class="font-mono text-xs break-all">{{ target.base_database.name }}</dd>
                        <dt class="text-gray-500">スキーマの指紋</dt>
                        <dd class="font-mono text-xs break-all">{{ target.base_database.schema_fingerprint ?? '—' }}</dd>
                    </dl>

                    <!-- いまの版 -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-600 mb-1">いまの版</h3>
                        <div v-if="target.current_version" class="flex flex-wrap items-center justify-between gap-2 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 text-sm">
                            <div class="space-y-0.5">
                                <p>
                                    <span class="font-mono font-semibold">{{ target.current_version.deploy_key }}</span>
                                    <span class="ml-2 font-mono text-xs text-gray-500">{{ shortHash(target.current_version.hash) }}</span>
                                    <span class="ml-2 text-xs text-gray-400">sys_deploy_id={{ target.current_version.sys_deploy_id }}</span>
                                </p>
                                <p class="text-xs text-gray-500">版の DB: {{ target.current_version.mst_database }}</p>
                                <p class="text-xs text-gray-500">start_at: <DateBoth :value="target.current_version.start_at" /></p>
                            </div>
                            <button class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 disabled:opacity-50" :disabled="!canMutate" @click="askDeactivate(target.current_version)">止める（緊急停止）</button>
                        </div>
                        <p v-else class="text-sm text-gray-500">いまの版はありません（ゲーム API は版が無いエラーを返す状態）。</p>
                    </div>

                    <!-- 予約済み -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-600 mb-1">予約済みの版（start_at が現在より後）</h3>
                        <div v-if="target.scheduled_list.length > 0" class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="px-3 py-2 text-left whitespace-nowrap">sys_deploy_id</th><th class="px-3 py-2 text-left whitespace-nowrap">deploy_key</th><th class="px-3 py-2 text-left whitespace-nowrap">hash</th>
                                        <th class="px-3 py-2 text-left whitespace-nowrap">start_at</th><th class="px-3 py-2 text-left whitespace-nowrap">状態</th><th class="px-3 py-2 text-right whitespace-nowrap">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="s in target.scheduled_list" :key="s.sys_deploy_id" class="border-t border-gray-100">
                                        <td class="px-3 py-2">{{ s.sys_deploy_id }}</td>
                                        <td class="px-3 py-2 font-mono whitespace-nowrap">{{ s.deploy_key }}</td>
                                        <td class="px-3 py-2 font-mono whitespace-nowrap">{{ shortHash(s.hash) }}</td>
                                        <td class="px-3 py-2">
                                            <DateBoth :value="s.start_at" />
                                            <p v-if="withinCancelWindow(s)" class="text-yellow-700 break-keep">120 秒以内のため取り消せません（止めるは使えます）</p>
                                        </td>
                                        <td class="px-3 py-2">
                                            <span v-if="!s.is_active" class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full whitespace-nowrap">止めた行</span>
                                            <span v-else class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full whitespace-nowrap">有効</span>
                                        </td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap space-x-1">
                                            <button class="px-2.5 py-1 bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50" :disabled="!canMutate" @click="askCancel(s)">取り消し</button>
                                            <button v-if="s.is_active" class="px-2.5 py-1 text-red-700 bg-red-50 border border-red-200 rounded hover:bg-red-100 disabled:opacity-50" :disabled="!canMutate" @click="askDeactivate(s)">止める</button>
                                            <button v-else class="px-2.5 py-1 text-blue-700 bg-blue-50 border border-blue-200 rounded hover:bg-blue-100 disabled:opacity-50" :disabled="!canMutate" @click="askActivate(s)">再開</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-sm text-gray-500">予約はありません。</p>
                    </div>

                    <!-- 止めた行（予約に出ていないもの） -->
                    <div v-if="inactiveOnly.length > 0">
                        <h3 class="text-sm font-semibold text-gray-600 mb-1">止めた行（緊急停止の履歴）</h3>
                        <ul class="space-y-1">
                            <li v-for="s in inactiveOnly" :key="s.sys_deploy_id" class="flex items-center justify-between bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs">
                                <span>sys_deploy_id={{ s.sys_deploy_id }} / <span class="font-mono">{{ s.deploy_key }}</span> / {{ shortHash(s.hash) }} / <DateBoth :value="s.start_at" /></span>
                                <button class="px-2.5 py-1 text-blue-700 bg-white border border-blue-200 rounded hover:bg-blue-50 disabled:opacity-50" :disabled="!canMutate" @click="askActivate(s)">再開</button>
                            </li>
                        </ul>
                    </div>

                    <!-- 直近の run -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-600 mb-1">直近の run</h3>
                        <div v-if="target.last_run" class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 text-xs text-gray-700 space-y-0.5">
                            <p>
                                <span class="font-semibold">#{{ target.last_run.id }} {{ target.last_run.kind }}</span>
                                <span :class="['ml-2 px-2 py-0.5 rounded-full', runStatusClass(target.last_run.status)]">{{ target.last_run.status }}</span>
                                <span v-if="target.last_run.phase" class="ml-2 text-gray-500">phase: {{ target.last_run.phase }}</span>
                            </p>
                            <p v-if="target.last_run.artifact_dir">成果物: {{ target.last_run.artifact_dir }}</p>
                            <p>開始 <DateBoth :value="target.last_run.started_at" /> / 終了 <DateBoth :value="target.last_run.finished_at" /></p>
                            <p v-if="target.last_run.error_code" class="text-red-700">{{ target.last_run.error_code }}: {{ target.last_run.error_message }}</p>
                            <button
                                v-if="target.last_run.error_code === 'register_unknown'"
                                class="mt-2 px-3 py-1.5 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
                                :disabled="!canMutate"
                                @click="askResolveUnknown(target.last_run)"
                            >この run を片付ける（resolve-unknown）</button>
                        </div>
                        <p v-else class="text-sm text-gray-500">実行記録はありません。</p>
                    </div>

                    <!-- 追従検出（build の QA 環境） -->
                    <div v-if="target.follow_check" class="text-xs text-gray-700">
                        <h3 class="text-sm font-semibold text-gray-600 mb-1">追従検出の直近の結果</h3>
                        状態 <code>{{ target.follow_check.status }}</code>
                        <span v-if="target.follow_check.artifact_dir"> / {{ target.follow_check.artifact_dir }}</span>
                        <span v-if="target.follow_check.checked_at"> / <DateBoth :value="target.follow_check.checked_at" /></span>
                        <span v-if="target.follow_check.reason"> / {{ target.follow_check.reason }}</span>
                    </div>

                    <!-- warning_list -->
                    <div v-if="target.warning_list.length > 0" class="space-y-1">
                        <h3 class="text-sm font-semibold text-yellow-700">警告</h3>
                        <p v-for="w in target.warning_list" :key="w.code" class="text-sm bg-yellow-50 border border-yellow-300 rounded-lg px-3 py-2 text-yellow-900">
                            <code class="font-semibold">{{ w.code }}</code> {{ w.message }}
                        </p>
                    </div>
                </section>

                <!-- 長い処理の案内 -->
                <div v-if="longNotice" class="bg-amber-50 border border-amber-300 rounded-lg p-4 text-sm text-amber-900" data-testid="long-notice">
                    <p class="font-semibold">{{ longNotice.title }}</p>
                    <p class="mt-1">
                        build・import などの長い処理は、画面の応答が 504 などで切れても<strong>サーバーでは最後まで続きます</strong>（途中で止まりません）。
                        二重に押さず、上の「直近の run」と「他の操作が実行中」の表示で完了を確認してください。
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        {{ polling ? '5 秒ごとに状態を自動で再取得しています。' : '自動の再取得は止まっています。' }}
                        <button class="ml-2 underline" @click="longNotice = null; stopPolling()">閉じる</button>
                    </p>
                </div>

                <!-- ================= build の環境 ================= -->
                <template v-if="role === 'build'">
                    <section v-if="!is_configured" class="bg-yellow-50 border border-yellow-300 rounded-lg p-4 text-sm text-yellow-800">
                        <p class="font-semibold">Google スプレッドシート連携が設定されていません</p>
                        <p class="mt-1">
                            <code class="bg-yellow-100 px-1 rounded">TOL_GOOGLE_SPREADSHEET_DIR</code> と
                            <code class="bg-yellow-100 px-1 rounded">TOL_GOOGLE_SERVICE_ACCOUNT_JSON_CONTENT</code> を <code class="bg-yellow-100 px-1 rounded">.env</code> に設定してください（build・QA 完了はシートを読みます）。
                        </p>
                    </section>

                    <section class="bg-white rounded-lg shadow p-6 space-y-4" data-testid="build-panel">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-700">build（シート → 成果物と版の DB → 登録）</h2>
                            <p class="text-sm text-gray-500 mt-1">全シートを読み、T（{{ target.deploy_key ?? '未設定' }}）以下を畳んで、新しい版を登録します。T・シート・表は指定しません。</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-600 mb-1">start_at（この版に切り替わる時刻）</h3>
                            <StartAtInput v-model="buildStartAt" v-model:immediate="buildImmediate" :now-utc="target.now" />
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button class="px-5 py-2.5 text-sm font-medium bg-white border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canBuild(true)" @click="runBuild(true)">
                                {{ busy === 'build-dry' ? '検証中...' : '検証（dry run）' }}
                            </button>
                            <button class="px-5 py-2.5 text-sm font-semibold bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canBuild(false)" @click="askBuild()">
                                {{ busy === 'build' ? 'build 中...' : '▶ build' }}
                            </button>
                        </div>
                        <p class="text-xs text-gray-500">dry run は DB・成果物・sys を変えず、ロックも取りません。成功しても、型・NOT NULL・<code>uk_*</code> の衝突は build で初めて分かります（<code>load_failed</code>）。</p>
                    </section>

                    <section class="bg-white rounded-lg shadow p-6 space-y-4" data-testid="finalize-panel">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-700">QA 完了（検証 → 確定）</h2>
                            <p class="text-sm text-gray-500 mt-1">成果物がシートの現在の内容に追従しているかを確かめ（検証）、問題なければ最終版の印（<code>final.json</code>）を付けます（確定）。確定は、同じ成果物の検証が成功した後に押せます。</p>
                        </div>
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-600">自環境の成果物</h3>
                            <button class="text-xs text-purple-600 hover:text-purple-800" :disabled="artifactsLoading" @click="loadArtifacts('own')">再読み込み</button>
                        </div>
                        <p v-if="artifactsError" class="text-sm text-red-700">{{ artifactsError }}</p>
                        <p v-else-if="artifactsLoading" class="text-sm text-gray-400">読み込み中...</p>
                        <p v-else-if="artifacts.length === 0" class="text-sm text-gray-500">成果物がありません。先に build してください。</p>
                        <ArtifactTable v-else v-model="finalizeSelected" :rows="artifacts" />
                        <div class="flex flex-wrap items-center gap-3">
                            <button class="px-5 py-2.5 text-sm font-medium bg-white border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canFinalizeDry" @click="runFinalize(true)">
                                {{ busy === 'finalize-dry' ? '検証中...' : '検証（dry run）' }}
                            </button>
                            <button class="px-5 py-2.5 text-sm font-semibold bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canFinalize" @click="askFinalize()">
                                {{ busy === 'finalize' ? '確定中...' : '✔ QA 完了にする（確定）' }}
                            </button>
                            <span v-if="finalizeSelected && finalizeVerified !== finalizeSelected" class="text-xs text-gray-500">先に「検証（dry run）」を成功させてください</span>
                            <span v-else-if="finalizeSelected" class="text-xs text-green-700">検証済み: 確定できます</span>
                        </div>
                        <p class="text-xs text-gray-500">シートを読めない場合（<code>follow_check_unavailable</code>）に警告つきで通す <code>--allow-unverified</code> は、画面には出さず CLI（<code>master:finalize</code>）だけで行います。</p>
                    </section>

                    <!-- シート一覧（確認用） -->
                    <section class="bg-white rounded-lg shadow p-6 space-y-3" data-testid="sheet-panel">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-700">シート一覧（読まれるタブ・読まれないタブの確認用）</h2>
                                <p class="text-sm text-gray-500 mt-1">build は全スプレッドシートの <code>mst_</code> で始まるタブを読みます。それ以外のタブは読まれません。選んで取り込むことはできません。</p>
                                <p v-if="cachedAt" class="text-xs text-gray-400 mt-1">キャッシュ: {{ cachedAt }} 取得（再取得するにはボタンを押してください）</p>
                            </div>
                            <button class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 whitespace-nowrap" :disabled="sheetsLoading || !is_configured" @click="loadAllSheets">
                                {{ sheetsLoading ? '読み込み中...' : (cachedAt ? '🔄 再取得' : '📥 シート一覧を取得') }}
                            </button>
                        </div>
                        <p v-if="sheetsError" class="text-sm text-red-700">{{ sheetsError }}</p>
                        <template v-if="allSheets.length > 0">
                            <input v-model="sheetSearch" type="text" placeholder="シート名・ファイル名で絞り込み..." class="w-72 px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent" />
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <h3 class="text-sm font-semibold text-green-700 mb-1">読まれるタブ（{{ readSheets.length }}）</h3>
                                    <div class="border border-green-200 bg-green-50 rounded-lg overflow-y-auto" style="height: 320px;">
                                        <div v-for="s in readSheets" :key="s.spreadsheet_id + s.sheet_title" class="px-3 py-1.5 border-b border-green-100">
                                            <p class="text-xs font-medium text-gray-800 truncate">{{ s.sheet_title }}</p>
                                            <p class="text-xs text-gray-400 truncate">{{ s.spreadsheet_name }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-500 mb-1">読まれないタブ（{{ ignoredSheets.length }}。<code>mst_</code> で始まらない）</h3>
                                    <div class="border border-gray-200 bg-gray-50 rounded-lg overflow-y-auto" style="height: 320px;">
                                        <div v-for="s in ignoredSheets" :key="s.spreadsheet_id + s.sheet_title" class="px-3 py-1.5 border-b border-gray-100 opacity-70">
                                            <p class="text-xs font-medium text-gray-800 truncate">{{ s.sheet_title }}</p>
                                            <p class="text-xs text-gray-400 truncate">{{ s.spreadsheet_name }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </section>
                </template>

                <!-- ================= import の環境 ================= -->
                <template v-else-if="role === 'import'">
                    <section class="bg-white rounded-lg shadow p-6 space-y-4" data-testid="import-panel">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-700">import（上流の成果物 → 版の DB → 登録）</h2>
                            <p class="text-sm text-gray-500 mt-1">上流の成果物から取り込む版を選び、切り替える時刻（start_at）を指定します。シートは読みません。</p>
                        </div>
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-600">上流の成果物</h3>
                            <button class="text-xs text-purple-600 hover:text-purple-800" :disabled="artifactsLoading" @click="loadArtifacts('upstream')">再読み込み</button>
                        </div>
                        <p v-if="artifactsError" class="text-sm text-red-700">{{ artifactsError }}</p>
                        <p v-else-if="artifactsLoading" class="text-sm text-gray-400">読み込み中...</p>
                        <p v-else-if="artifacts.length === 0" class="text-sm text-gray-500">取り込める成果物がありません（<code>manifest.json</code> のあるものだけ出します）。</p>
                        <ArtifactTable v-else v-model="importSelected" :rows="artifacts" />
                        <div>
                            <h3 class="text-sm font-semibold text-gray-600 mb-1">start_at（この版に切り替わる時刻。必須）</h3>
                            <StartAtInput v-model="importStartAt" v-model:immediate="importImmediate" :now-utc="target.now" />
                            <p class="mt-1 text-xs text-gray-400">production は例えば 0 時 UTC、staging は production と同じ値にします（過去でもよい。production 以外）。「すぐ」を選ぶと登録の直前の現在時刻です。</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button class="px-5 py-2.5 text-sm font-medium bg-white border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canImport()" @click="runImport(true)">
                                {{ busy === 'import-dry' ? '検証中...' : '検証（dry run）' }}
                            </button>
                            <button class="px-5 py-2.5 text-sm font-semibold bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canImport()" @click="askImport()">
                                {{ busy === 'import' ? '取り込み中...' : '▶ 取り込む' }}
                            </button>
                        </div>
                        <p class="text-xs text-gray-500">dry run も一時の DB を作るため、他の操作が実行中のときは使えません（終わると片付けます。何も登録しません）。</p>
                    </section>
                </template>

                <section v-else class="bg-yellow-50 border border-yellow-300 rounded-lg p-4 text-sm text-yellow-800">
                    <code>MASTER_DEPLOY_ROLE</code> が build か import に設定されていないため、build・import の画面は出せません。
                </section>

                <!-- ================= 版の一覧 ================= -->
                <section class="bg-white rounded-lg shadow p-6 space-y-3" data-testid="version-panel">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-700">版の一覧（巻き戻し）</h2>
                        <p class="text-sm text-gray-500 mt-1">戻したい版を指す新しい切り替え行を足します（DB・成果物は作り直しません。掃除済みの版は成果物から復元します）。予約があるときは、先に取り消してください。</p>
                    </div>
                    <div v-if="target.version_list.length > 0" class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left whitespace-nowrap">版（deploy_key / hash）</th>
                                    <th class="px-3 py-2 text-left whitespace-nowrap">版の DB / 由来・スキーマ</th>
                                    <th class="px-3 py-2 text-left whitespace-nowrap">最後の切り替え</th>
                                    <th class="px-3 py-2 text-right whitespace-nowrap">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in target.version_list" :key="v.sys_deploy_master_id" :class="['border-t border-gray-100 align-top', v.is_current ? 'bg-purple-50' : '']">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <p class="font-mono">
                                            {{ v.deploy_key }}
                                            <span v-if="v.is_current" class="ml-1 px-1.5 py-0.5 bg-purple-600 text-white rounded font-sans">いまの版</span>
                                        </p>
                                        <p class="font-mono text-gray-500">{{ shortHash(v.hash) }}</p>
                                    </td>
                                    <td class="px-3 py-2 break-all">
                                        <p>{{ v.mst_database ?? '—' }}</p>
                                        <p v-if="v.mst_dropped_at" class="text-gray-400">掃除済み <DateBoth :value="v.mst_dropped_at" /></p>
                                        <p class="mt-0.5 whitespace-nowrap text-gray-500">
                                            由来 {{ v.source_kind }} ／ スキーマ
                                            <span :class="v.schema_matches ? 'text-green-700' : 'text-red-700'">{{ v.schema_matches ? '一致' : '不一致' }}</span>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <template v-if="v.last_start_at">
                                            <DateBoth :value="v.last_start_at" />
                                        </template>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-3 py-2 text-right break-keep">
                                        <span v-if="v.is_current" class="text-gray-400">戻す必要はありません</span>
                                        <span v-else-if="v.rollback_state === 'blocked_by_scheduled'" class="text-yellow-700">先に予約を取り消してください</span>
                                        <span v-else-if="v.rollback_state === 'schema_mismatch'" class="text-red-700">スキーマが不一致のため戻せません</span>
                                        <span v-else-if="v.rollback_state === 'unavailable'" class="text-red-700">DB も成果物も無く戻せません</span>
                                        <div v-else class="flex flex-col items-end gap-1">
                                            <button class="px-2.5 py-1 text-white bg-orange-500 rounded hover:bg-orange-600 disabled:opacity-50 whitespace-nowrap" :disabled="!canMutate" @click="askRollback(v)">
                                                この版に戻す<span v-if="v.rollback_state === 'needs_restore'">（DB を復元）</span>
                                            </button>
                                            <button class="px-2.5 py-1 bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 whitespace-nowrap" :disabled="busy !== null" @click="runRollback(v, true)">dry run</button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-gray-500">版がありません。</p>
                </section>

                <!-- ================= 掃除など ================= -->
                <section class="bg-white rounded-lg shadow p-6 space-y-3" data-testid="maintenance-panel">
                    <h2 class="text-lg font-semibold text-gray-700">掃除・キャッシュ</h2>
                    <p class="text-sm text-gray-500">古い版の DB・失敗した run の孤児・一時の場所を片付けます（手動。保持する版の数・猶予は環境設定）。まず dry run で対象を確認してから実行します。成果物・基底 DB は消しません。</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <button class="px-4 py-2 text-sm font-medium bg-white border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 disabled:opacity-50" :disabled="busy !== null" @click="runCleanup(true)">
                            {{ busy === 'cleanup-dry' ? '確認中...' : '掃除（dry run）' }}
                        </button>
                        <button class="px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!canCleanupExecute" @click="askCleanup()">
                            {{ busy === 'cleanup' ? '掃除中...' : '上の確認内容で掃除を実行' }}
                        </button>
                        <span v-if="!cleanupPlanned" class="text-xs text-gray-400">先に dry run を実行してください</span>
                        <span v-else-if="cleanupPlanned.drop_count === 0" class="text-xs text-gray-400">消す対象はありません</span>
                        <span class="flex-1"></span>
                        <button class="px-4 py-2 text-xs text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50" :disabled="busy !== null" @click="runClearCache">
                            {{ busy === 'clear-cache' ? '破棄中...' : 'キャッシュ破棄（clear-cache）' }}
                        </button>
                    </div>
                </section>

                <!-- ================= 結果 ================= -->
                <div ref="resultAnchor">
                    <ResultPanel v-if="result" :result="result" @clear-cache="runClearCache" />
                </div>

                <!-- ================= 運営向けの説明 ================= -->
                <section class="bg-white rounded-lg shadow p-6">
                    <details :open="role === 'build'">
                        <summary class="text-lg font-semibold text-gray-700 cursor-pointer select-none">シートの書き方と切り替えの決まり</summary>
                        <ol class="mt-3 space-y-2 text-sm text-gray-700 list-none">
                            <li>① 古い行は消さず、<strong>新しいキー（<code>deploy_key</code>）の行を足します</strong>。</li>
                            <li>② 削除は <code>__deleted</code> を <code>TRUE</code> にした行（<code>id</code> と <code>deploy_key</code> だけでよい）で表します。<code>is_active</code> を無効にしても消えません（A 列の <code>is_active</code> はシートの行の話で、緊急停止の <code>sys_deploy.is_active</code> とは別です）。</li>
                            <li>③ <code>deploy_key</code> は 10 桁が必須です。<strong>環境の T より大きい <code>deploy_key</code> の行は畳みに入りません</strong>（効かせたいなら T を上げて build します）。<strong>時刻による切り替えは <code>start_at</code> で行います</strong>（採番が追いつくまで効かせる仕組みはありません）。</li>
                            <li>④ 言語だけを消すことはできません（親ごと消します）。</li>
                            <li>⑤ 「検証（dry run）」が成功しても、型・NOT NULL・<code>uk_*</code> の衝突は build・import で初めて分かり、<code>load_failed</code> になることがあります（版の DB は作られません）。</li>
                            <li>⑥ <strong>確定済み（production に出た）版の行の変更は、新しい <code>deploy_key</code> で入れます</strong>（production の取り込みが検査します）。</li>
                            <li>⑦ シートに行が無い表は、版の DB で空になります。</li>
                        </ol>
                    </details>
                </section>
            </template>
        </div>

        <ConfirmDialog
            :open="dialog !== null"
            :title="dialog?.title ?? ''"
            :lines="dialog?.lines ?? []"
            :note="dialog?.note ?? ''"
            :danger="dialog?.danger ?? false"
            :confirm-label="dialog?.confirmLabel ?? '実行する'"
            @confirm="confirmDialog"
            @cancel="dialog = null"
        />
    </DashboardLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, defineComponent, h } from 'vue';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import ConfirmDialog from '@/Components/MasterImport/ConfirmDialog.vue';
import ResultPanel from '@/Components/MasterImport/ResultPanel.vue';
import StartAtInput from '@/Components/MasterImport/StartAtInput.vue';
import DateBoth from '@/Components/MasterImport/DateBoth.vue';
import { both, diffSeconds, parseUtc, shortHash, toJst } from '@/Components/MasterImport/format';

const props = defineProps({
    auth: Object,
    is_configured: Boolean,
    folder_id: String,
    cached_sheets: Array,   // サーバー側のキャッシュファイルから渡される（シート一覧の確認用）
    cached_at: String,
    target: Object,         // api.md 4 節。読めなかったときは null
});

// 成果物の選択表（build の QA 完了・import で共通）
const ArtifactTable = defineComponent({
    props: { modelValue: String, rows: Array },
    emits: ['update:modelValue'],
    setup(p, { emit }) {
        const badge = (ok, yes, no) => h('span', { class: ok ? 'text-green-700' : 'text-gray-400' }, ok ? yes : no);
        return () => h('div', { class: 'overflow-x-auto border border-gray-200 rounded-lg' }, [
            h('table', { class: 'min-w-full text-xs' }, [
                h('thead', { class: 'bg-gray-50 text-gray-600' }, h('tr', ['', '成果物', '作成（UTC / JST）', '作成した環境', '最終版', '取り込み済み', 'スキーマ']
                    .map((t) => h('th', { class: 'px-3 py-2 text-left whitespace-nowrap' }, t)))),
                h('tbody', p.rows.map((a) => h('tr', {
                    key: a.artifact_dir,
                    class: ['border-t border-gray-100 cursor-pointer', p.modelValue === a.artifact_dir ? 'bg-purple-50' : 'hover:bg-gray-50'],
                    onClick: () => emit('update:modelValue', a.artifact_dir),
                }, [
                    h('td', { class: 'px-3 py-2' }, h('input', { type: 'radio', checked: p.modelValue === a.artifact_dir, onChange: () => emit('update:modelValue', a.artifact_dir) })),
                    h('td', { class: 'px-3 py-2 font-mono' }, a.artifact_dir),
                    h('td', { class: 'px-3 py-2' }, h(DateBoth, { value: a.built_at })),
                    h('td', { class: 'px-3 py-2' }, a.built_env),
                    h('td', { class: 'px-3 py-2' }, badge(a.finalized, '確定済み', '未確定')),
                    h('td', { class: 'px-3 py-2' }, badge(a.imported, '済み', '未')),
                    h('td', { class: 'px-3 py-2' }, h('span', { class: a.schema_matches ? 'text-green-700' : 'text-red-700' }, a.schema_matches ? '一致' : '不一致')),
                ]))),
            ]),
        ]);
    },
});

// ---- 対象 ----
const target = ref(props.target ?? null);
const loadingTarget = ref(false);
const role = computed(() => target.value?.role ?? '');

// 取り消し・巻き戻しの保護の幅（2 × schedule_ttl。api.md 9.2）
const CANCEL_WINDOW_SECONDS = 120;

const inactiveOnly = computed(() => {
    const scheduledIds = new Set((target.value?.scheduled_list ?? []).map((s) => s.sys_deploy_id));
    return (target.value?.inactive_list ?? []).filter((s) => !scheduledIds.has(s.sys_deploy_id));
});

const withinCancelWindow = (s) => {
    const d = diffSeconds(s.start_at, target.value?.now);
    return d !== null && d <= CANCEL_WINDOW_SECONDS;
};

const runStatusClass = (status) => ({
    succeeded: 'bg-green-100 text-green-700',
    running: 'bg-amber-100 text-amber-800',
    failed: 'bg-red-100 text-red-700',
    aborted: 'bg-gray-200 text-gray-700',
}[status] ?? 'bg-gray-100 text-gray-700');

const loadTarget = async ({ silent = false } = {}) => {
    if (!silent) loadingTarget.value = true;
    try {
        const res = await axios.get('/master-import/target');
        target.value = res.data;
        return res.data;
    } catch (e) {
        if (!silent) alert('状態の再取得に失敗しました: ' + (e.response?.data?.message ?? e.message));
        return null;
    } finally {
        loadingTarget.value = false;
    }
};

// ---- 実行状態 ----
const busy = ref(null);           // 実行中の操作名（二重クリックの防止）
const result = ref(null);         // 結果の表示
const resultAnchor = ref(null);
const longNotice = ref(null);     // 504 などで応答が切れたときの案内
const polling = ref(false);
let pollTimer = null;
let pollCount = 0;
let pollInFlight = false;

// 他の操作が実行中のときは、書き込む操作・ロックを取る操作を出さない
const canMutate = computed(() => busy.value === null && target.value !== null && !target.value.running);
const blocked = computed(() => target.value?.blocked ?? null);

const startPolling = () => {
    stopPolling();
    polling.value = true;
    pollCount = 0;
    pollTimer = setInterval(async () => {
        if (pollInFlight) return; // 前の取得が終わっていなければ重ねない
        pollInFlight = true;
        pollCount += 1;
        let t;
        try {
            t = await loadTarget({ silent: true });
        } finally {
            pollInFlight = false;
        }
        // 実行中でなくなったら止める（取れなければ最大 5 分で諦める）
        if ((t && !t.running) || pollCount >= 60) {
            stopPolling();
            if (t && !t.running) await refreshLists();
        }
    }, 5000);
};
const stopPolling = () => {
    if (pollTimer !== null) clearInterval(pollTimer);
    pollTimer = null;
    polling.value = false;
};
onBeforeUnmount(stopPolling);

/**
 * 操作を呼ぶ。応答が JSON でない（504 など。処理はサーバーで続く）ときは案内を出して状態を追う。
 * @returns {Promise<object|null>} 成功時の応答。失敗・切断は null
 */
const call = async (op, method, url, payload, label, kind = null) => {
    busy.value = op;
    longNotice.value = null;
    try {
        const res = await axios({ method, url, ...(method === 'get' ? { params: payload } : { data: payload }) });
        if (!res.data || typeof res.data !== 'object') {
            // 200 でも JSON でない（ログイン切れのログイン画面の HTML など）。成功扱いにしない
            setResult({ label, ok: false, http_status: res.status, data: { status: 'error', message: '応答が JSON ではありません。ログインが切れていないか確認して、ページを開き直してください。' }, kind });
            return null;
        }
        setResult({ label, ok: true, http_status: res.status, data: res.data, kind });
        return res.data;
    } catch (e) {
        const body = e.response?.data;
        const status = e.response?.status;
        if (body && typeof body === 'object') {
            setResult({ label, ok: false, http_status: status, data: normalizeError(body), kind });
        } else if (!e.response || [502, 503, 504].includes(status)) {
            // 届かない（ネットワーク切断）・ゲートウェイの 502/503/504。処理は続いている可能性がある
            result.value = null;
            longNotice.value = {
                title: `「${label}」の応答が途中で切れました${e.response ? `（HTTP ${e.response.status}）` : ''}。`,
            };
            startPolling();
        } else {
            // 500・413 など JSON でない失敗は、処理が続くとは言えないので失敗として出す
            setResult({ label, ok: false, http_status: status, data: { status: 'error', message: `サーバーがエラーを返しました（HTTP ${status}）。ログを確認してください。` }, kind });
        }
        return null;
    } finally {
        busy.value = null;
    }
};

// Laravel 標準の 422（message・errors）を error_list にそろえる
const normalizeError = (body) => {
    if (body.errors && typeof body.errors === 'object' && !body.error_list) {
        return {
            ...body,
            status: 'error',
            error_list: Object.entries(body.errors).flatMap(([field, msgs]) => [].concat(msgs).map((message) => ({ field, message }))),
        };
    }
    return body;
};

const setResult = (r) => {
    result.value = r;
    nextTick(() => resultAnchor.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
};

const refreshLists = async () => {
    await loadTarget({ silent: true });
    if (role.value === 'import') await loadArtifacts('upstream');
    if (role.value === 'build') await loadArtifacts('own');
};

// ---- 確認ダイアログ ----
const dialog = ref(null);
const ask = (spec, action) => { dialog.value = { ...spec, action }; };
const confirmDialog = () => {
    const action = dialog.value?.action;
    dialog.value = null;
    // ダイアログを開いている間に他の操作が始まっていないか、確定時に確かめる
    if (!canMutate.value) {
        alert('他の操作が実行中のため、実行しませんでした。状態を再取得して、終わってからやり直してください。');
        return;
    }
    if (action) action();
};
const baseLines = (operation) => [
    ['環境', `${target.value.app_env}（role=${target.value.role}）`],
    ['操作', operation],
];
const isProduction = computed(() => target.value?.app_env === 'production');

// ---- start_at ----
const buildImmediate = ref(true);
const buildStartAt = ref('');
const importImmediate = ref(false);
const importStartAt = ref('');
const startAtOk = (immediate, value) => immediate || parseUtc(value) !== null;
const startAtPayload = (immediate, value) => (immediate ? null : value);
const startAtLine = (immediate, value) => ['start_at', immediate ? 'すぐ（登録の直前の現在時刻）' : both(value)];

// ---- 成果物 ----
const artifacts = ref([]);
const artifactsLoading = ref(false);
const artifactsError = ref('');
const loadArtifacts = async (source) => {
    artifactsLoading.value = true;
    artifactsError.value = '';
    try {
        const res = await axios.get('/master-import/artifact-list', { params: { source } });
        artifacts.value = res.data.artifact_list ?? [];
    } catch (e) {
        artifacts.value = [];
        artifactsError.value = `成果物の一覧を取得できませんでした: ${e.response?.data?.message ?? e.message}`;
    } finally {
        artifactsLoading.value = false;
    }
};

// ---- build ----
const canBuild = (dry) => busy.value === null && !blocked.value && startAtOk(buildImmediate.value, buildStartAt.value)
    && (dry || !target.value.running);
const runBuild = (dry) => call(
    dry ? 'build-dry' : 'build', 'post', '/master-import/build',
    { dry_run: dry, start_at: startAtPayload(buildImmediate.value, buildStartAt.value) },
    dry ? 'build の検証（dry run）' : 'build',
).then((data) => { if (data && !dry) refreshLists(); });
const askBuild = () => ask({
    title: 'build を実行します',
    danger: isProduction.value,
    confirmLabel: 'build する',
    lines: [
        ...baseLines('build（シート → 成果物と版の DB → 登録）'),
        ['T（deploy_key）', String(target.value.deploy_key ?? '—')],
        startAtLine(buildImmediate.value, buildStartAt.value),
    ],
    note: '時間がかかります。画面の応答が切れても処理はサーバーで続きます。',
}, () => runBuild(false));

// ---- QA 完了（finalize） ----
const finalizeSelected = ref('');
const finalizeVerified = ref('');   // 検証（dry run）が成功した成果物
const canFinalizeDry = computed(() => busy.value === null && !blocked.value && !!finalizeSelected.value);
const canFinalize = computed(() => canMutate.value && !blocked.value && !!finalizeSelected.value && finalizeVerified.value === finalizeSelected.value);
const runFinalize = async (dry) => {
    const dir = finalizeSelected.value;
    if (dry) finalizeVerified.value = ''; // 検証の開始時に外す（失敗・切断でも確定できる状態を残さない）
    const data = await call(
        dry ? 'finalize-dry' : 'finalize', 'post', '/master-import/finalize',
        { artifact_dir: dir, dry_run: dry },
        dry ? 'QA 完了の検証（dry run）' : 'QA 完了（確定）',
    );
    if (data && dry) finalizeVerified.value = data.follow_check?.status === 'match' ? dir : '';
    if (!dry) finalizeVerified.value = '';
    if (data && !dry) refreshLists();
};
const askFinalize = () => ask({
    title: 'QA 完了（最終版の確定）',
    danger: isProduction.value,
    confirmLabel: '確定する',
    lines: [...baseLines('QA 完了（final.json を書く）'), ['T（deploy_key）', String(target.value.deploy_key ?? '—')], ['成果物', finalizeSelected.value]],
    note: '確定した成果物だけが、上流として import できます（上書きはしません）。',
}, () => runFinalize(false));

// ---- import ----
const importSelected = ref('');
// import は dry run も一時の DB を作ってロックを取るので、実行中は両方とも押せない
const canImport = () => busy.value === null && !blocked.value && !!importSelected.value
    && startAtOk(importImmediate.value, importStartAt.value) && !target.value.running;
const runImport = (dry) => call(
    dry ? 'import-dry' : 'import', 'post', '/master-import/import',
    { artifact_dir: importSelected.value, start_at: startAtPayload(importImmediate.value, importStartAt.value), dry_run: dry },
    dry ? 'import の検証（dry run）' : 'import',
).then((data) => { if (data && !dry) refreshLists(); });
const askImport = () => ask({
    title: '成果物を取り込みます',
    danger: isProduction.value,
    confirmLabel: '取り込む',
    lines: [
        ...baseLines('import（成果物を取り込み、切り替えを予約）'),
        ['成果物', importSelected.value],
        startAtLine(importImmediate.value, importStartAt.value),
    ],
    note: importImmediate.value
        ? '「すぐ」は登録の直前の時刻で切り替わります。二重に押すと行が 2 つ増えます。'
        : 'start_at に到達すると、ゲーム API がこの版に切り替わります。',
}, () => runImport(false));

// ---- 巻き戻し ----
const runRollback = (v, dry) => call(
    dry ? 'rollback-dry' : 'rollback', 'post', '/master-import/rollback',
    { sys_deploy_master_id: v.sys_deploy_master_id, dry_run: dry },
    dry ? `巻き戻しの確認（dry run: ${v.deploy_key}）` : `巻き戻し（${v.deploy_key}）`,
).then((data) => { if (data && !dry) refreshLists(); });
const askRollback = (v) => ask({
    title: 'この版に戻します',
    danger: isProduction.value,
    confirmLabel: '戻す',
    lines: [
        ...baseLines('巻き戻し（戻し先を指す新しい切り替え行を足す）'),
        ['戻し先', `${v.deploy_key} / ${shortHash(v.hash)}（sys_deploy_master_id=${v.sys_deploy_master_id}）`],
        ['start_at', 'max(現在, 直前の最新行) + 1 秒'],
        ['版の DB', v.rollback_state === 'needs_restore' ? '掃除済みのため、成果物から復元します' : '既存の DB を使います'],
    ],
}, () => runRollback(v, false));

// ---- 予約の取り消し・緊急停止・再開 ----
const deployLines = (s) => [
    ['対象', `sys_deploy_id=${s.sys_deploy_id} / deploy_key=${s.deploy_key} / ${shortHash(s.hash)}`],
    ['start_at', both(s.start_at)],
];
const askCancel = (s) => ask({
    title: '予約を取り消します',
    danger: isProduction.value,
    confirmLabel: '取り消す',
    lines: [...baseLines('予約の取り消し（sys_deploy の行を削除）'), ...deployLines(s)],
    note: 'start_at まで 120 秒以内の予約は取り消せません。その場合は「止める」で対応します。',
}, () => call('cancel', 'post', '/master-import/cancel-schedule', { sys_deploy_id: s.sys_deploy_id }, '予約の取り消し').then((data) => { if (data) refreshLists(); }));
const askDeactivate = (s) => ask({
    title: '緊急停止します（この切り替え行を止める）',
    danger: isProduction.value,
    confirmLabel: '止める',
    lines: [...baseLines('緊急停止（is_active を落とす）'), ...deployLines(s)],
    note: '止めた後に配信できる版が無くなる止め方は拒否されます。start_at の 120 秒以内の行を止めると、取り消しも巻き戻しもできないので、start_at の到達を待ってください。キャッシュの都合で、最大 60 秒は止めた版が配信されることがあります。',
}, () => call('deactivate', 'post', '/master-import/deactivate', { sys_deploy_id: s.sys_deploy_id }, '緊急停止').then((data) => { if (data) refreshLists(); }));
const askActivate = (s) => ask({
    title: '止めた行を再開します',
    confirmLabel: '再開する',
    lines: [...baseLines('再開（is_active を戻す）'), ...deployLines(s)],
    note: '掃除済みの版が最新の選択になる場合は、成果物から DB を復元します（時間がかかることがあります）。',
}, () => call('activate', 'post', '/master-import/activate', { sys_deploy_id: s.sys_deploy_id }, '再開').then((data) => { if (data) refreshLists(); }));

// ---- 掃除・キャッシュ・片付け ----
const cleanupPlanned = ref(null);   // dry run の結果（{ drop_count }）
const canCleanupExecute = computed(() => canMutate.value && cleanupPlanned.value !== null && cleanupPlanned.value.drop_count > 0);
const runCleanup = async (dry) => {
    const data = await call(dry ? 'cleanup-dry' : 'cleanup', 'post', '/master-import/cleanup', { dry_run: dry }, dry ? '掃除の確認（dry run）' : '掃除の実行', 'cleanup');
    if (data && dry) cleanupPlanned.value = { drop_count: (data.drop_list ?? []).length };
    if (!dry) { cleanupPlanned.value = null; refreshLists(); }
};
const askCleanup = () => ask({
    title: '掃除を実行します',
    danger: isProduction.value,
    confirmLabel: '掃除する',
    lines: [...baseLines('掃除（版の DB・孤児・一時の場所を削除）'), ['対象', `dry run で確認した ${cleanupPlanned.value.drop_count} 件（実行時に再計算します）`]],
    note: '実行は、ロックを取った後に対象を再計算して消します。dry run の一覧と違うことがあります。',
}, () => runCleanup(false));
const runClearCache = async () => {
    if (busy.value !== null) return;
    await call('clear-cache', 'post', '/master-import/clear-cache', {}, 'キャッシュ破棄（clear-cache）');
    loadTarget({ silent: true });
};
const askResolveUnknown = (run) => ask({
    title: 'register_unknown の run を片付けます',
    danger: isProduction.value,
    confirmLabel: '片付ける',
    lines: [...baseLines('resolve-unknown（sys を再確認して片付ける）'), ['run', `#${run.id}（${run.kind}）`]],
    note: 'sys に登録済みなら run を成功に直して DB は残し、未登録で、その run が作った DB なら DROP します。',
}, () => call('resolve-unknown', 'post', '/master-import/resolve-unknown', { run_id: run.id }, 'register_unknown の片付け').then((data) => { if (data) refreshLists(); }));

// ---- シート一覧（build の確認用） ----
const allSheets = ref([]);
const sheetsLoading = ref(false);
const sheetsError = ref('');
const sheetSearch = ref('');
const cachedAt = ref(props.cached_at ?? null);
const filteredSheets = computed(() => {
    const q = sheetSearch.value.toLowerCase();
    return q ? allSheets.value.filter((s) => s.sheet_title.toLowerCase().includes(q) || s.spreadsheet_name.toLowerCase().includes(q)) : allSheets.value;
});
const readSheets = computed(() => filteredSheets.value.filter((s) => s.is_mst));
const ignoredSheets = computed(() => filteredSheets.value.filter((s) => !s.is_mst));
const loadAllSheets = async () => {
    sheetsLoading.value = true;
    sheetsError.value = '';
    try {
        const res = await axios.get('/master-import/all-sheets');
        allSheets.value = res.data.sheets ?? [];
        cachedAt.value = res.data.cached_at ?? null;
    } catch (e) {
        sheetsError.value = 'シート一覧の取得に失敗しました: ' + (e.response?.data?.message ?? e.message);
    } finally {
        sheetsLoading.value = false;
    }
};

onMounted(() => {
    if (props.cached_sheets?.length > 0) allSheets.value = props.cached_sheets;
    if (role.value === 'import') loadArtifacts('upstream');
    if (role.value === 'build') loadArtifacts('own');
    // 開いた時点で他の操作が動いていれば、終わるまで状態を追う
    if (target.value?.running) startPolling();
});
</script>
