<script setup>
/**
 * Marca uno o varios pedidos como retirados. Registra quien y cuando via
 * Order::markWithdrawn (backend), con observacion opcional.
 */
import { ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  show: { type: Boolean, default: false },
  orders: { type: Array, required: true },
})

const emit = defineEmits(['close', 'done'])

const toast = useToast()
const notes = ref('')
const saving = ref(false)
const error = ref(null)

watch(() => props.show, (visible) => {
  if (visible) { notes.value = ''; error.value = null }
})

async function submit() {
  saving.value = true
  error.value = null
  try {
    const { data } = await axios.post('/orders/bulk-withdraw', {
      order_ids: props.orders.map((o) => o.id),
      notes: notes.value || null,
    })
    toast.success(`${data.updated} pedido(s) marcado(s) como retirado(s).`)
    emit('done')
    emit('close')
  } catch (e) {
    error.value = 'No se pudo marcar el retiro. Verifica tus permisos.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :show="show" @close="$emit('close')">
    <div class="p-5">
      <h2 class="text-lg font-medium text-gray-900 mb-2">Marcar como retirado</h2>
      <p class="text-sm text-gray-600 mb-4">
        {{ orders.length }} pedido(s) seleccionado(s). Se registrara la fecha y tu usuario.
      </p>

      <label class="text-sm text-gray-600 block mb-1">Observacion (opcional)</label>
      <textarea v-model="notes" rows="2" class="w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>

      <p v-if="error" class="text-red-600 text-sm mt-2">{{ error }}</p>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving" @click="submit">
          {{ saving ? 'Guardando...' : 'Confirmar retiro' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
