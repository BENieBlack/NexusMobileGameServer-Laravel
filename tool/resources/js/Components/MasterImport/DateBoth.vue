<template>
    <span v-if="!value">—</span>
    <span v-else-if="!jst">{{ value }}</span>
    <!-- UTC と JST を別々の nowrap にして、間でだけ折り返す（「JS／T」の途中で切れない） -->
    <span v-else><span class="whitespace-nowrap">{{ value }} UTC</span><wbr /> <span class="whitespace-nowrap">（{{ jst }} JST）</span></span>
</template>

<script setup>
import { computed } from 'vue';
import { toJst } from './format';

const props = defineProps({ value: { type: String, default: null } });
const jst = computed(() => toJst(props.value));
</script>
