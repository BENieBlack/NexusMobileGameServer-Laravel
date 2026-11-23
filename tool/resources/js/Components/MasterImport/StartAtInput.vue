<template>
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" :checked="immediate" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" @change="$emit('update:immediate', $event.target.checked)" />
            すぐ（登録の直前の現在時刻にする）
        </label>
        <div v-if="!immediate" class="flex flex-wrap items-center gap-3">
            <input
                :value="modelValue"
                type="text"
                inputmode="numeric"
                placeholder="2026-12-01 15:00:00"
                maxlength="19"
                class="w-56 px-3 py-1.5 text-sm font-mono border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <span class="text-xs text-gray-500">UTC で入力（Y-m-d H:i:s）</span>
            <span v-if="jst" class="text-xs text-purple-700 font-medium">= {{ jst }} JST</span>
            <span v-else-if="modelValue" class="text-xs text-red-600">形式が正しくありません</span>
        </div>
        <p v-for="(h, i) in hints" :key="i" class="text-xs text-yellow-700 bg-yellow-50 border border-yellow-200 rounded px-2 py-1">{{ h }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { diffSeconds, toJst } from './format';

// nowUtc: サーバーの現在時刻（target.now）。過去・近すぎを事前に示すための目安（判定はサーバー）
const props = defineProps({
    modelValue: { type: String, default: '' },
    immediate: { type: Boolean, default: true },
    nowUtc: { type: String, default: null },
    tooSoonSeconds: { type: Number, default: 120 },
});
defineEmits(['update:modelValue', 'update:immediate']);

const jst = computed(() => toJst(props.modelValue));

const hints = computed(() => {
    if (props.immediate || !jst.value || !props.nowUtc) return [];
    const d = diffSeconds(props.modelValue, props.nowUtc);
    if (d === null) return [];
    if (d < 0) return ['過去の時刻です。production では拒否されます（許容幅 60 秒以内は現在に丸めて通します）。'];
    if (d <= props.tooSoonSeconds) return [`現在から ${d} 秒後です。${props.tooSoonSeconds} 秒以内の予約は取り消せません（警告 start_at_too_soon）。`];
    return [];
});
</script>
