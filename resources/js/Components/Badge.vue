<script setup>
defineProps({
    variant: {
        type: String,
        default: 'neutral',
        validator: (v) => ['success', 'warning', 'danger', 'neutral'].includes(v),
    },
});

const classesByVariant = {
    success: 'bg-herb text-white',
    warning: 'bg-maize text-white',
    danger: 'bg-garnet text-white',
    neutral: 'bg-surface-3 text-gray-200',
};
</script>

<template>
    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium" :class="classesByVariant[variant]">
        <slot />
    </span>
</template>
