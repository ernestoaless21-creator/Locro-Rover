<script setup>
/**
 * Asigna un Rover a uno o varios pedidos. Sirve tanto para la accion masiva
 * desde la tabla de pedidos (varios seleccionados) como para reasignar UN
 * pedido desde su pantalla de edicion (se le pasa un array de un elemento).
 */
import { ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  show: { type: Boolean, default: false },
  orders: { type: Array, required: true }, // [{id, client:{first_name,last_name}}]
  rovers: { type: Array, required: true },
})

const emit = defineEmits(['close', 'done'])

const toast = useToast()
const roverId = ref(null)
const saving = ref(false)
const error = ref(null)

watch(() => props.show, (visible) => {
  if (visible) { roverId.value = null; error.value = null }
})

async function submit() {
  if (!roverId.value) {
    error.value = 'Selecciona un Rover.'
    return
  }
  saving.value = true
  error.value = null
  try {
    const { data } = await axios.post('/orders/bulk-assign', {
      order_ids: props.orders.map((o) => o.id),
      rover_id: roverId.value,
    })
    toast.success(`${data.updated} pedido(s) reasignado(s).`)
    emit('done')
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo asignar. Verifica tus permisos.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :show="show" @close="$emit('close')">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 mb-2">Asignar Rover</h2>
      <p class="text-sm text-gray-600 mb-4">
        {{ orders.length }} pedido(s) seleccionado(s).
      </p>

      <select v-model="roverId" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
        <option :value="null" disabled>Elegir Rover...</option>
        <option v-for="r in rovers" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>

      <p v-if="error" class="text-red-600 text-sm mt-2">{{ error }}</p>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving" @click="submit">
          {{ saving ? 'Asignando...' : 'Confirmar asignacion' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
