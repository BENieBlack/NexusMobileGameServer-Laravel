<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="$emit('cancel')">
        <div class="w-full max-w-lg bg-white rounded-xl shadow-xl overflow-hidden" role="dialog" aria-modal="true">
            <div :class="['px-5 py-3 border-b', danger ? 'bg-red-50 border-red-200' : 'bg-gray-50 border-gray-200']">
                <h3 :class="['text-base font-semibold', danger ? 'text-red-800' : 'text-gray-800']">{{ title }}</h3>
            </div>
            <dl class="px-5 py-4 space-y-2 text-sm">
                <div v-for="(line, i) in lines" :key="i" class="flex gap-3">
                    <dt class="w-28 flex-shrink-0 text-gray-500">{{ line[0] }}</dt>
                    <dd class="flex-1 text-gray-800 break-words"><BothText :text="line[1]" /></dd>
                </div>
            </dl>
            <p v-if="note" class="px-5 pb-3 text-xs text-gray-500">{{ note }}</p>
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex justify-end gap-2">
                <button class="px-4 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50" @click="$emit('cancel')">やめる</button>
                <button
                    :class="['px-4 py-2 text-sm font-semibold text-white rounded-lg', danger ? 'bg-red-600 hover:bg-red-700' : 'bg-purple-600 hover:bg-purple-700']"
                    @click="$emit('confirm')"
                >{{ confirmLabel }}</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import BothText from './BothText.vue';

defineProps({
    open: Boolean,
    title: String,
    lines: { type: Array, default: () => [] }, // [[見出し, 値], ...]
    note: { type: String, default: '' },
    danger: Boolean,
    confirmLabel: { type: String, default: '実行する' },
});
defineEmits(['confirm', 'cancel']);
</script>
