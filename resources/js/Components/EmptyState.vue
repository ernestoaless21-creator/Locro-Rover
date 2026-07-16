<script setup>
/**
 * Fase 18: estado vacio reutilizable para toda la app (Pedidos, Clientes,
 * Compras, Publicidad, Logistica, etc.). El icono se pasa por slot (no un
 * registro interno de nombres) para que cada modulo use el Lucide que le
 * corresponda sin tener que tocar este componente cada vez que se adopta en
 * una pantalla nueva.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
})
</script>

<template>
    <div class="text-center py-10">
        <div v-if="$slots.icon" class="flex justify-center mb-3 text-gray-600">
            <slot name="icon" />
        </div>
        <p class="text-gray-300 font-medium">{{ title }}</p>
        <p v-if="description" class="text-gray-500 text-sm mt-1">{{ description }}</p>
        <div v-if="$slots.action" class="mt-3">
            <slot name="action" />
        </div>
    </div>
</template>
