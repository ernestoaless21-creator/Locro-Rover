<script setup>
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  team:       { type: String,  required: true },
  years:      { type: Array,   required: true },
  targetYear: { type: Object,  required: true },
  sourceData: { type: Object,  default: null },
})

const sourceYearId = ref(props.sourceData?.source_year_id ?? null)
const targetYearId = ref(props.targetYear.id)

function loadPreview() {
  if (!sourceYearId.value || !targetYearId.value) return
  router.get(
    route('infrastructure.import', { team: props.team }),
    { source_year_id: sourceYearId.value, target_year_id: targetYearId.value },
    { preserveState: true }
  )
}

watch([sourceYearId, targetYearId], () => loadPreview())

const sourceItems = computed(() => props.sourceData?.items ?? [])
const existingItemIds = computed(() => new Set(props.sourceData?.existing_item_ids ?? []))

const checked = ref({})

function resetSelection() {
  const state = {}
  for (const item of sourceItems.value) {
    // Los elementos que ya están en el destino arrancan destildados para no
    // reintentar una duplicación que el backend igual va a rechazar.
    state[item.infrastructure_item_id] = !existingItemIds.value.has(item.infrastructure_item_id)
  }
  checked.value = state
}

watch(sourceItems, resetSelection, { immediate: true })

const selectedCount = computed(() => Object.values(checked.value).filter(Boolean).length)
const sameYear = computed(() => sourceYearId.value === targetYearId.value)

const form = useForm({
  source_year_id: props.sourceData?.source_year_id ?? null,
  target_year_id: props.targetYear.id,
  selected_item_ids: [],
})

function submit() {
  if (!sourceYearId.value || !targetYearId.value || sameYear.value) return
  const ids = Object.entries(checked.value).filter(([, v]) => v).map(([k]) => Number(k))
  if (ids.length === 0) return

  form.source_year_id = sourceYearId.value
  form.target_year_id = targetYearId.value
  form.selected_item_ids = ids
  form.post(route('infrastructure.import.store', { team: props.team }))
}
</script>

<template>
  <AppLayout title="Importar inventario de infraestructura">
    <template #header>
      <div class="flex items-center gap-4">
        <a :href="route('infrastructure.index', { team })" class="text-xs text-ember hover:text-ember-strong uppercase tracking-wide">
          ← Inventario de infraestructura
        </a>
        <h2 class="font-semibold text-xl text-white leading-tight">Importar desde otra edición</h2>
      </div>
    </template>

    <div class="py-8">
      <div class="max-w-2xl mx-auto px-4 sm:px-6 space-y-6">

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
          <p class="text-sm text-gray-600">
            Importar elementos del inventario de una edición anterior como base para esta edición.
            Se copian la cantidad necesaria y la cantidad nuestra disponible como referencia inicial.
            La cantidad en reparación NO se copia (podés cargarla de nuevo si sigue vigente).
            No se importan préstamos: pertenecen a su propia edición.
            Los elementos que ya están en esta edición se omiten para evitar duplicados.
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Edición origen</label>
            <select v-model="sourceYearId" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option :value="null">Seleccionar edición…</option>
              <option v-for="y in years" :key="y.id" :value="y.id" :disabled="y.id === targetYearId">
                {{ y.label }}{{ y.is_active ? ' (activa)' : '' }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Edición destino</label>
            <select v-model="targetYearId" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option v-for="y in years" :key="y.id" :value="y.id" :disabled="y.id === sourceYearId">
                {{ y.label }}{{ y.is_active ? ' (activa)' : '' }}
              </option>
            </select>
          </div>

          <p v-if="sameYear" class="text-sm text-red-600">El origen y el destino no pueden ser la misma edición.</p>

          <div v-if="sourceItems.length > 0 && !sameYear" class="space-y-2">
            <p class="text-sm font-medium text-gray-700">Elegí qué elementos importar</p>
            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 max-h-96 overflow-y-auto">
              <label
                v-for="item in sourceItems"
                :key="item.infrastructure_item_id"
                class="flex items-center gap-2 p-2.5 cursor-pointer"
                :class="existingItemIds.has(item.infrastructure_item_id) ? 'opacity-50' : ''"
              >
                <input type="checkbox" v-model="checked[item.infrastructure_item_id]" class="rounded text-indigo-600 focus:ring-indigo-500" />
                <span class="flex-1 text-sm text-gray-700">{{ item.item_name }}</span>
                <span class="text-xs text-gray-400 font-mono">
                  necesarias: {{ item.needed_quantity }} · nuestras: {{ item.own_available_quantity }}
                </span>
                <span v-if="existingItemIds.has(item.infrastructure_item_id)" class="text-xs text-amber-600 whitespace-nowrap">
                  ya existe
                </span>
              </label>
            </div>
          </div>

          <p v-else-if="sourceData && sourceItems.length === 0 && !sameYear" class="text-sm text-amber-700">
            La edición origen no tiene elementos en el inventario.
          </p>

          <div class="flex gap-3 pt-2">
            <button
              type="button"
              :disabled="!sourceYearId || !targetYearId || sameYear || form.processing || selectedCount === 0"
              class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-40"
              @click="submit"
            >
              Importar {{ selectedCount > 0 ? `(${selectedCount})` : '' }}
            </button>
            <a :href="route('infrastructure.index', { team })" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">
              Volver
            </a>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>
