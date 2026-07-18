<script setup>
/**
 * Fase 19: aviso visible cuando la pagina esta mostrando una edicion que NO
 * es la activa. Puramente informativo -- el bloqueo real de crear/editar/
 * eliminar ya esta aplicado por separado (ver useEditableYear.js en el
 * frontend, Gate::authorize('mutate', $year) en el backend). No se muestra
 * nada si la edicion es la activa.
 */
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
  year: { type: Object, required: true },
})

const page = usePage()
const canEdit = computed(() => (page.props.permissions ?? []).includes('anios.gestionar'))
</script>

<template>
  <div
    v-if="!year.is_active"
    class="flex items-center gap-2 rounded-md border border-amber-700/50 bg-amber-900/20 px-3 py-2 text-sm text-amber-200"
  >
    <span>📖</span>
    <span>
      Estás viendo una edición histórica ({{ year.label || `Locro ${year.year}` }}), de solo lectura.
      <template v-if="canEdit"> Como administrador, igual podés editarla.</template>
      <template v-else> No se pueden crear, editar ni eliminar registros acá.</template>
    </span>
  </div>
</template>
