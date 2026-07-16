<script setup>
defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <select
        :value="modelValue"
        @change="$emit('update:modelValue', $event.target.value)"
        class="w-full bg-surface border border-border-soft text-white rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ember focus:border-ember"
    >
        <slot />
    </select>
</template>
