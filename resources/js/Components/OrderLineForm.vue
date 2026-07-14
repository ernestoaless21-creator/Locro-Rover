<script setup>
/**
 * FASE 5C: este formulario ya NO ofrece crear lineas nuevas tipo 'regalo'
 * (los regalos/donaciones tienen su propio modulo independiente, ver Gift).
 * Solo permite agregar la excepcion 'personalizado' (precio excepcional),
 * gateada en el backend por el permiso 'pedidos.precio-excepcional'
 * (Store/UpdateOrderItemRequest, StoreOrderRequest, PricingController).
 *
 * Si se abre para EDITAR una linea historica que ya es 'regalo' (creada
 * antes de este cambio), se preserva esa opcion en el selector para no
 * romper el dato existente, pero no aparece al crear una linea nueva.
 */
import { computed, ref, watch, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  yearId: { type: Number, required: true },
  initial: { type: Object, default: null }, // para editar una linea existente
})

const emit = defineEmits(['submit', 'cancel'])

const TYPES = computed(() => {
  const base = [{ value: 'personalizado', label: 'Precio personalizado / descuento' }]
  if (props.initial?.type === 'regalo') {
    return [{ value: 'regalo', label: 'Regalo (registro histórico)' }, ...base]
  }
  return base
})

const type = ref(props.initial?.type ?? 'personalizado')
const quantity = ref(props.initial?.quantity ?? 1)
const description = ref(props.initial?.description ?? '')
const customUnitPrice = ref(props.initial?.custom_unit_price ?? null)

const preview = ref(null)
const previewErrors = ref({})
const previewing = ref(false)
let debounceTimer = null

function fetchPreview() {
  clearTimeout(debounceTimer)
  previewErrors.value = {}

  if (!quantity.value || quantity.value < 1) {
    preview.value = null
    return
  }
  if (type.value === 'personalizado' && !customUnitPrice.value) {
    preview.value = null
    return
  }

  debounceTimer = setTimeout(async () => {
    previewing.value = true
    try {
      const { data } = await axios.post('/pricing/preview', {
        year_id: props.yearId,
        product: 'locro',
        type: type.value,
        quantity: quantity.value,
        custom_unit_price: type.value === 'personalizado' ? customUnitPrice.value : null,
      })
      preview.value = data
    } catch (e) {
      preview.value = null
      if (e.response?.status === 422) {
        previewErrors.value = e.response.data.errors ?? {}
      }
    } finally {
      previewing.value = false
    }
  }, 350)
}

onMounted(fetchPreview)
watch([type, quantity, customUnitPrice], fetchPreview)

function submit() {
  if (!preview.value) return

  emit('submit', {
    product: 'locro',
    type: type.value,
    quantity: Number(quantity.value),
    description: description.value || null,
    custom_unit_price: type.value === 'personalizado' ? Number(customUnitPrice.value) : null,
    unit_price: preview.value.unit_price,
    line_total: preview.value.line_total,
  })

  type.value = 'personalizado'
  quantity.value = 1
  description.value = ''
  customUnitPrice.value = null
}

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}
</script>

<template>
  <div class="border border-gray-700 rounded-md p-3 space-y-2">
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
      <div>
        <label class="text-xs text-gray-400">Tipo de excepcion</label>
        <select v-model="type" :disabled="initial?.type === 'regalo'" class="w-full bg-gray-900 border border-gray-600 rounded-md px-2 py-1 text-sm disabled:opacity-60">
          <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-400">Porciones</label>
        <input
          v-model.number="quantity"
          type="number"
          min="1"
          class="w-full bg-gray-900 border border-gray-600 rounded-md px-2 py-1 text-sm"
        />
      </div>
      <div v-if="type === 'personalizado'">
        <label class="text-xs text-gray-400">Precio unitario</label>
        <input
          v-model.number="customUnitPrice"
          type="number"
          min="0"
          step="0.01"
          class="w-full bg-gray-900 border border-gray-600 rounded-md px-2 py-1 text-sm"
        />
      </div>
    </div>

    <input
      v-model="description"
      type="text"
      placeholder="Motivo / descripcion (opcional)"
      class="w-full bg-gray-900 border border-gray-600 rounded-md px-2 py-1 text-sm"
    />

    <div v-if="Object.keys(previewErrors).length" class="text-red-400 text-xs">
      <div v-for="(msg, field) in previewErrors" :key="field">{{ msg }}</div>
    </div>

    <div class="flex items-center justify-between">
      <div class="text-sm">
        <span v-if="previewing" class="text-gray-400">Calculando...</span>
        <span v-else-if="preview">
          {{ quantity }} × {{ money(preview.unit_price) }} = <strong>{{ money(preview.line_total) }}</strong>
        </span>
      </div>
      <div class="flex gap-2">
        <button
          v-if="initial"
          type="button"
          class="text-gray-400 hover:text-gray-300 text-sm"
          @click="$emit('cancel')"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="bg-blue-600 hover:bg-blue-500 disabled:opacity-40 px-3 py-1 rounded-md text-sm"
          :disabled="!preview || previewing"
          @click="submit"
        >
          {{ initial ? 'Guardar cambios' : '+ Agregar excepcion' }}
        </button>
      </div>
    </div>
  </div>
</template>
