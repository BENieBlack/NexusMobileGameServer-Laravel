<template>
    <!-- both() で作った「… UTC（… JST）」の文字列を、UTC と JST の間でだけ折り返す。それ以外はそのまま -->
    <span v-if="parts"><span class="whitespace-nowrap">{{ parts[1] }}</span><wbr /> <span class="whitespace-nowrap">{{ parts[2] }}</span></span>
    <span v-else>{{ text }}</span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ text: { type: String, default: '' } });
const parts = computed(() => /^(\S+ \S+ UTC)(（.* JST）)$/.exec(props.text));
</script>
