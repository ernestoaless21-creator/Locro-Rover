<script setup>
import { useToast } from '@/Composables/useToast'

const { toasts, dismiss } = useToast()
</script>

<template>
  <div class="fixed bottom-4 right-4 z-50 space-y-2 w-80">
    <transition-group name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="rounded-md shadow-lg px-4 py-3 text-sm text-white flex items-start justify-between gap-2 cursor-pointer"
        :class="{
          'bg-green-600': toast.type === 'success',
          'bg-red-600': toast.type === 'error',
          'bg-blue-600': toast.type === 'info',
        }"
        @click="dismiss(toast.id)"
      >
        <span>{{ toast.message }}</span>
        <button type="button" class="opacity-70 hover:opacity-100">&times;</button>
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.2s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
</style>
