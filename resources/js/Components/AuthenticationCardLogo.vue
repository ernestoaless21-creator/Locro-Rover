<script setup>
import { Link } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
</script>

<template>
    <Link :href="'/'">
        <ApplicationMark class="h-16 w-auto" />
    </Link>
</template>
