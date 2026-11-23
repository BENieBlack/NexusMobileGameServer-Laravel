<template>
    <div class="bg-white rounded-lg shadow p-6" data-testid="result-panel">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold text-gray-700">結果: {{ result.label }}</h2>
            <span v-if="result.http_status" class="text-xs text-gray-400">HTTP {{ result.http_status }}</span>
        </div>

        <!-- 概要 -->
        <div :class="['rounded-lg border p-3 text-sm', ok ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200']">
            <p class="font-medium" :class="ok ? 'text-green-800' : 'text-red-800'">
                {{ ok ? '✅' : '❌' }} {{ d.message ?? (ok ? '完了しました。' : '失敗しました。') }}
            </p>
            <p class="mt-1 text-xs text-gray-600 space-x-3">
                <span v-if="d.dry_run === true" class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded">dry run（何も変えていません）</span>
                <span v-if="d.code">code: <code>{{ d.code }}</code></span>
                <span v-if="d.run_id">run_id: {{ d.run_id }}</span>
            </p>
        </div>

        <!-- エラー内訳 -->
        <div v-if="errorRows.length > 0" class="mt-4">
            <h3 class="text-sm font-semibold text-red-700 mb-1">
                エラー（{{ errorTotal }} 件<template v-if="errorTotal > ERROR_LIMIT">中、先頭 {{ ERROR_LIMIT }} 件を表示</template>）
            </h3>
            <DataTable :rows="errorRows" />
            <p v-if="d.error_count_by_code" class="mt-1 text-xs text-gray-500">
                code ごとの件数: {{ Object.entries(d.error_count_by_code).map(([k, v]) => `${k}=${v}`).join(' / ') }}
            </p>
        </div>

        <!-- 警告 -->
        <div v-if="warnings.length > 0" class="mt-4 space-y-1">
            <h3 class="text-sm font-semibold text-yellow-700">警告（止めない注意）</h3>
            <p v-for="(w, i) in warnings" :key="i" class="text-xs bg-yellow-50 border border-yellow-200 rounded px-3 py-2 text-yellow-800">
                <code class="font-semibold">{{ w.code }}</code> {{ w.message }}
            </p>
            <button
                v-if="hasCacheWarning"
                class="mt-1 text-xs px-3 py-1.5 bg-yellow-100 text-yellow-800 rounded hover:bg-yellow-200"
                @click="$emit('clear-cache')"
            >clear-cache を実行（schedule キーの破棄を再試行）</button>
        </div>

        <!-- 登録した版 -->
        <div v-if="d.deploy" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">版</h3>
            <dl class="grid grid-cols-[10rem_1fr] gap-y-1 text-xs text-gray-700">
                <template v-for="[k, v] in deployRows" :key="k">
                    <dt class="text-gray-500">{{ k }}</dt>
                    <dd class="break-words"><BothText :text="v" /></dd>
                </template>
            </dl>
        </div>
        <div v-if="d.cancelled" class="mt-4 text-xs text-gray-700">
            取り消した予約: sys_deploy_id={{ d.cancelled.sys_deploy_id }} / deploy_key={{ d.cancelled.deploy_key }} / <DateBoth :value="d.cancelled.start_at" />
        </div>
        <div v-if="d.current_version_after !== undefined" class="mt-2 text-xs text-gray-700">
            操作後のいまの版:
            <template v-if="d.current_version_after">deploy_key={{ d.current_version_after.deploy_key }}（sys_deploy_id={{ d.current_version_after.sys_deploy_id }}）</template>
            <template v-else>なし</template>
        </div>
        <div v-if="d.remaining_scheduled_list?.length" class="mt-2">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">残っている予約</h3>
            <DataTable :rows="d.remaining_scheduled_list" />
        </div>
        <p v-if="d.resolved" class="mt-2 text-xs text-gray-700">片付け結果: {{ d.resolved === 'registered' ? '登録済みだった（DB は残した）' : '未登録だった（DB を DROP した）' }}</p>

        <!-- 追従検出 -->
        <div v-if="d.follow_check" class="mt-4 text-xs text-gray-700">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">追従検出</h3>
            状態: <code>{{ d.follow_check.status }}</code>
            <span v-if="d.follow_check.reason">（{{ d.follow_check.reason }}）</span>
            <span v-if="d.follow_check.changed_deploy_key_list?.length"> / 変わった deploy_key: {{ d.follow_check.changed_deploy_key_list.join(', ') }}</span>
        </div>

        <!-- 表ごとの件数 -->
        <div v-if="d.build?.table_list?.length" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">表ごとの件数（{{ d.build.table_list.length }} 表）</h3>
            <DataTable
                :rows="d.build.table_list"
                :columns="[
                    { key: 'table', label: '表' }, { key: 'input_rows', label: '読んだ行' },
                    { key: 'ignored_inactive_rows', label: '無効で無視' }, { key: 'adopted_rows', label: '採用（畳み後）' },
                    { key: 'empty', label: '空の表' },
                ]"
            />
        </div>
        <div v-if="d.import?.table_list?.length" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">表ごとの件数（{{ d.import.table_list.length }} 表）</h3>
            <DataTable
                :rows="d.import.table_list"
                :columns="[{ key: 'table', label: '表' }, { key: 'row_count', label: '行数（マニフェスト）' }, { key: 'loaded', label: '投入した行' }]"
            />
        </div>
        <div v-if="d.import?.check_list?.length" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">登録前の検査（I1〜I6）</h3>
            <DataTable :rows="d.import.check_list" :columns="[{ key: 'check', label: '検査' }, { key: 'result', label: '結果' }, { key: 'detail', label: '詳細' }]" />
        </div>
        <p v-if="d.import?.reference" class="mt-2 text-xs text-gray-700">
            参照版 A: deploy_key={{ d.import.reference.deploy_key }}（sys_deploy_master_id={{ d.import.reference.sys_deploy_master_id }}）
            <span v-if="d.import.reference.restored">/ 成果物から復元して比べた</span>
        </p>
        <div v-if="d.build?.ignored_tab_list?.length" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">読まなかったタブ（{{ d.build.ignored_tab_list.length }}）</h3>
            <DataTable :rows="d.build.ignored_tab_list" :columns="[{ key: 'sheet', label: 'タブ' }, { key: 'reason', label: '理由（not_mst_tab / empty）' }]" />
        </div>
        <div v-if="d.build?.ignored_column_list?.length" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">mst に無いため無視した列（{{ d.build.ignored_column_list.length }}）</h3>
            <DataTable :rows="d.build.ignored_column_list" :columns="[{ key: 'table', label: '表' }, { key: 'column', label: '列' }]" />
        </div>
        <p v-if="d.artifact" class="mt-4 text-xs text-gray-700">
            成果物: <code>{{ d.artifact.artifact_dir }}</code>（{{ artifactSummary }}）
            <span v-if="d.artifact.reused">/ 同じ成果物が既にあり再利用</span>
        </p>

        <!-- 掃除 -->
        <template v-if="isCleanup">
            <div v-if="d.keep_list?.length" class="mt-4">
                <h3 class="text-sm font-semibold text-gray-600 mb-1">残す版（{{ d.keep_list.length }}）</h3>
                <DataTable :rows="d.keep_list" />
            </div>
            <div class="mt-4">
                <h3 class="text-sm font-semibold text-gray-600 mb-1">{{ d.dry_run ? '消す対象（実行すると消えます）' : '消す対象（再計算の結果）' }}（{{ d.drop_list?.length ?? 0 }}）</h3>
                <DataTable v-if="d.drop_list?.length" :rows="d.drop_list" />
                <p v-else class="text-xs text-gray-500">対象はありません。</p>
            </div>
            <div v-if="d.dropped_list?.length" class="mt-4">
                <h3 class="text-sm font-semibold text-green-700 mb-1">消したもの（{{ d.dropped_list.length }}）</h3>
                <DataTable :rows="d.dropped_list" />
            </div>
            <div v-if="d.failed_list?.length" class="mt-4">
                <h3 class="text-sm font-semibold text-red-700 mb-1">消せなかったもの（{{ d.failed_list.length }}）</h3>
                <DataTable :rows="d.failed_list" />
            </div>
        </template>

        <!-- 棚卸し -->
        <div v-if="d.inventory" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-1">9 桁の棚卸し（dry run のみ）</h3>
            <p class="text-xs text-gray-600 mb-2">
                畳みの勝者が変わる鍵の数: <strong>{{ d.inventory.order_change_key_count }}</strong>
                <span v-if="d.inventory.legacy_count_by_category"> / 9 桁の行: {{ Object.entries(d.inventory.legacy_count_by_category).map(([k, v]) => `${k}=${v}`).join(' ・ ') }}</span>
            </p>
            <DataTable
                v-if="distributionRows.length"
                :rows="distributionRows"
                :columns="[
                    { key: 'table', label: '表' }, { key: 'nine_digit', label: '9 桁' }, { key: 'ten_digit_real', label: '10 桁（本物）' },
                    { key: 'ten_digit_reserved', label: '10 桁（予約）' }, { key: 'other', label: 'その他' }, { key: 'empty', label: '空' },
                ]"
            />
            <template v-if="d.inventory.conversion_conflict_list?.length">
                <h4 class="text-xs font-semibold text-gray-600 mt-3 mb-1">変換の衝突（{{ d.inventory.conversion_conflict_list.length }}）</h4>
                <DataTable :rows="d.inventory.conversion_conflict_list.slice(0, ERROR_LIMIT)" />
            </template>
            <template v-if="d.inventory.legacy_row_list?.length">
                <h4 class="text-xs font-semibold text-gray-600 mt-3 mb-1">9 桁の行（先頭 {{ Math.min(d.inventory.legacy_row_list.length, ERROR_LIMIT) }} 件）</h4>
                <DataTable :rows="d.inventory.legacy_row_list.slice(0, ERROR_LIMIT)" />
            </template>
        </div>

        <details class="mt-4">
            <summary class="text-xs text-gray-400 cursor-pointer select-none">応答の生データ</summary>
            <pre class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded text-xs overflow-auto max-h-96">{{ JSON.stringify(d, null, 2) }}</pre>
        </details>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import DataTable from './DataTable.vue';
import DateBoth from './DateBoth.vue';
import BothText from './BothText.vue';
import { both, shortHash } from './format';

const ERROR_LIMIT = 20;

// result: { label, ok, http_status, data, kind }
const props = defineProps({ result: { type: Object, required: true } });
defineEmits(['clear-cache']);

const d = computed(() => props.result.data ?? {});
const ok = computed(() => props.result.ok);
const isCleanup = computed(() => props.result.kind === 'cleanup');

const errorRows = computed(() => (Array.isArray(d.value.error_list) ? d.value.error_list.slice(0, ERROR_LIMIT) : []));
const errorTotal = computed(() => d.value.error_total ?? (d.value.error_list?.length ?? 0));

const warnings = computed(() => [
    ...(d.value.warning_list ?? []),
    ...(d.value.build?.warning_list ?? []),
    ...(d.value.import?.warning_list ?? []),
]);
const hasCacheWarning = computed(() => warnings.value.some((w) => w.code === 'schedule_cache_not_invalidated'));

const deployRows = computed(() => {
    const x = d.value.deploy ?? {};
    const rows = [];
    const add = (label, value) => { if (value !== undefined && value !== null) rows.push([label, String(value)]); };
    add('deploy_key（T）', x.deploy_key);
    add('mode', x.mode);
    add('hash', x.hash ? shortHash(x.hash) + '…' : null);
    add('artifact_dir', x.artifact_dir);
    add('版の DB', x.mst_database);
    add('sys_deploy_master_id', x.sys_deploy_master_id);
    add('sys_deploy_id', x.sys_deploy_id);
    if ('start_at' in x) rows.push(['start_at', both(x.start_at)]);
    add('新しい版か', x.is_new === undefined ? null : (x.is_new ? 'はい' : 'いいえ（同じ内容の版を再利用）'));
    add('DB を再利用', x.database_reused === undefined ? null : (x.database_reused ? 'はい' : 'いいえ'));
    add('DB を復元', x.restored === undefined ? null : (x.restored ? 'はい' : 'いいえ'));
    add('戻す前の sys_deploy_id', x.previous_sys_deploy_id);
    add('is_active', x.is_active === undefined ? null : (x.is_active ? '有効' : '止めている'));
    return rows;
});

// build の応答は table_count・file_size を持つが、import の応答は持たない（table_list と tables から求める）
const artifactSummary = computed(() => {
    const a = d.value.artifact ?? {};
    const parts = [];
    const tableCount = a.table_count ?? d.value.import?.table_list?.length ?? (a.tables ? Object.keys(a.tables).length : null);
    if (tableCount !== null && tableCount !== undefined) parts.push(`${tableCount} 表`);
    if (a.file_count !== undefined) parts.push(`${a.file_count} ファイル`);
    const size = a.file_size ?? (a.tables ? Object.values(a.tables).reduce((sum, t) => sum + (t.file_size ?? 0), 0) : null);
    if (size !== null && size !== undefined) parts.push(`${(size / 1024).toFixed(1)} KB`);
    return parts.join(' / ');
});

const distributionRows = computed(() =>
    Object.entries(d.value.inventory?.digit_distribution ?? {}).map(([table, v]) => ({ table, ...v })),
);
</script>
