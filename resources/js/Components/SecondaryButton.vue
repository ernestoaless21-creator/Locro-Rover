<script setup>
import AppButton from '@/Components/AppButton.vue';

defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <AppButton variant="secondary" :type="type">
        <slot />
    </AppButton>
</template>
