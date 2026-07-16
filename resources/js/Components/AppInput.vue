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
    <input
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        class="w-full bg-surface border border-border-soft text-white placeholder-gray-500 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ember focus:border-ember"
    />
</template>
