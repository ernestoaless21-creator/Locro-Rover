<script setup>
/**
 * Selector global de anio (2024 | 2025 | 2026 | 2027...). Al cambiar,
 * navega via Inertia con ?year_id=, preservando la ruta actual, para que
 * cada pagina (pedidos, historial, etc.) recargue con los datos de ese anio.
 *
 * IMPORTANTE: esto solo cambia que anio se esta VIENDO en esta navegacion,
 * NO cambia el anio activo global de la organizacion (eso es una accion de
 * administrador aparte, ver YearController@activate). Por eso el resaltado
 * usa 'selectedYearId' (lo que la pagina actual esta mostrando), no
 * 'currentYear' (el anio activo compartido globalmente).
 */
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
  selectedYearId: { type: Number, default: null },
})

const page = usePage()
const years = computed(() => page.props.years ?? [])
const currentYear = computed(() => page.props.currentYear ?? null)
const activeSelection = computed(() => props.selectedYearId ?? currentYear.value?.id ?? null)

function selectYear(yearId) {
  router.get(
    window.location.pathname,
    { year_id: yearId },
    { preserveState: true, preserveScroll: true, replace: true }
  )
}
</script>

<template>
  <div class="flex items-center gap-1 flex-wrap">
    <button
      v-for="y in years"
      :key="y.id"
      type="button"
      class="px-3 py-1 rounded-md text-sm font-medium transition-colors"
      :class="[
        y.id === activeSelection ? 'bg-surface-3 text-white' : 'bg-surface text-gray-300 hover:bg-gray-700',
        y.is_active ? 'ring-1 ring-green-500' : '',
      ]"
      :title="y.is_active ? 'Edicion activa' : ''"
      @click="selectYear(y.id)"
    >
      {{ y.year }}
    </button>
  </div>
</template>
