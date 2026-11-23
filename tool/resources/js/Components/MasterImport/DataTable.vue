<template>
    <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th v-for="col in cols" :key="col.key" class="px-3 py-2 text-left font-semibold whitespace-nowrap">{{ col.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="i" class="border-t border-gray-100">
                    <td v-for="col in cols" :key="col.key" class="px-3 py-1.5 text-gray-700 align-top">{{ cell(row[col.key]) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { computed } from 'vue';

// columns を省略すると、行のキーの和集合を列にする
const props = defineProps({
    rows: { type: Array, required: true },
    columns: { type: Array, default: null }, // [{ key, label }]
});

const cols = computed(() => {
    if (props.columns) return props.columns;
    const keys = [];
    props.rows.forEach((row) => Object.keys(row ?? {}).forEach((k) => { if (!keys.includes(k)) keys.push(k); }));
    return keys.map((key) => ({ key, label: key }));
});

const cell = (value) => {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'はい' : 'いいえ';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};
</script>
