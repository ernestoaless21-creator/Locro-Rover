<script setup>
defineProps({
    title: {
        type: String,
        default: null,
    },
    padding: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

const paddingClasses = {
    sm: 'p-4',
    md: 'p-5',
    lg: 'p-6',
};
</script>

<template>
    <div class="bg-surface border border-border text-white rounded-lg" :class="paddingClasses[padding]">
        <h3 v-if="title" class="text-sm font-semibold text-gray-400 mb-3">{{ title }}</h3>
        <slot />
    </div>
</template>
