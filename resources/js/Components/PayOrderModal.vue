<script setup>
/**
 * Registra pagos (payments reales, nunca un booleano) para uno o varios
 * pedidos. Dos modos:
 * - Saldo total: cada pedido recibe un pago por SU propio saldo pendiente.
 * - Montos fijos: se pueden cargar una o mas lineas de medio de pago que se
 *   aplican identicas a cada pedido seleccionado (soporta pago parcial y
 *   varios medios a la vez, ej: $10.000 efectivo + $5.000 transferencia).
 */
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  show: { type: Boolean, default: false },
  orders: { type: Array, required: true }, // [{id, balance_due, client:{...}}]
  paymentMethods: { type: Array, required: true }, // [{id, name}] -> Efectivo, Transferencia
})

const emit = defineEmits(['close', 'done'])

const toast = useToast()
const mode = ref('full_balance')
const fullBalanceMethodId = ref(null)
const lines = ref([{ payment_method_id: null, amount: null }])
const paidAt = ref(new Date().toISOString().slice(0, 10))
const notes = ref('')
const saving = ref(false)
const error = ref(null)
const confirmOverpayment = ref(false)

watch(() => props.show, (visible) => {
  if (!visible) return
  mode.value = 'full_balance'
  fullBalanceMethodId.value = props.paymentMethods[0]?.id ?? null
  lines.value = [{ payment_method_id: props.paymentMethods[0]?.id ?? null, amount: null }]
  paidAt.value = new Date().toISOString().slice(0, 10)
  notes.value = ''
  error.value = null
  confirmOverpayment.value = false
})

const totalBalance = computed(() => props.orders.reduce((sum, o) => sum + Number(o.balance_due), 0))

function addLine() {
  lines.value.push({ payment_method_id: props.paymentMethods[0]?.id ?? null, amount: null })
}
function removeLine(index) {
  lines.value.splice(index, 1)
}

function money(value) {
  return `$${Number(value).toLocaleString('es-AR')}`
}

async function submit() {
  saving.value = true
  error.value = null

  const payload = {
    order_ids: props.orders.map((o) => o.id),
    mode: mode.value,
    paid_at: paidAt.value,
    notes: notes.value || null,
    confirm_overpayment: confirmOverpayment.value,
  }

  if (mode.value === 'full_balance') {
    payload.payment_method_id = fullBalanceMethodId.value
  } else {
    payload.lines = lines.value.filter((l) => l.payment_method_id && l.amount > 0)
  }

  try {
    const { data } = await axios.post('/orders/bulk-pay', payload)
    toast.success(`${data.payments_created} pago(s) registrado(s).`)
    emit('done')
    emit('close')
  } catch (e) {
    if (e.response?.status === 422 && e.response.data.errors?.lines) {
      error.value = e.response.data.errors.lines[0]
      confirmOverpayment.value = true // el proximo submit ya manda la confirmacion
    } else {
      error.value = 'No se pudo registrar el pago.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :show="show" @close="$emit('close')">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 mb-2">Registrar pago</h2>
      <p class="text-sm text-gray-600 mb-4">
        {{ orders.length }} pedido(s) — saldo total pendiente: {{ money(totalBalance) }}
      </p>

      <div class="flex gap-4 mb-4 text-sm">
        <label class="flex items-center gap-1">
          <input v-model="mode" type="radio" value="full_balance" /> Pagar saldo total
        </label>
        <label class="flex items-center gap-1">
          <input v-model="mode" type="radio" value="fixed_lines" /> Montos / medios especificos
        </label>
      </div>

      <div v-if="mode === 'full_balance'">
        <label class="text-sm text-gray-600 block mb-1">Medio de pago</label>
        <select v-model="fullBalanceMethodId" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
          <option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
      </div>

      <div v-else class="space-y-2">
        <div v-for="(line, index) in lines" :key="index" class="flex gap-2 items-center">
          <select v-model="line.payment_method_id" class="flex-1 border-gray-300 rounded-md shadow-sm text-sm">
            <option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
          <input
            v-model.number="line.amount"
            type="number"
            min="0.01"
            step="0.01"
            placeholder="Monto (por cada pedido)"
            class="flex-1 border-gray-300 rounded-md shadow-sm text-sm"
          />
          <button v-if="lines.length > 1" type="button" class="text-red-500 text-xs" @click="removeLine(index)">
            Quitar
          </button>
        </div>
        <button type="button" class="text-blue-600 text-xs" @click="addLine">+ Agregar medio de pago</button>
      </div>

      <div class="grid grid-cols-2 gap-4 mt-4">
        <div>
          <label class="text-sm text-gray-600 block mb-1">Fecha</label>
          <input v-model="paidAt" type="date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
        </div>
      </div>

      <div class="mt-4">
        <label class="text-sm text-gray-600 block mb-1">Observacion (opcional)</label>
        <textarea v-model="notes" rows="2" class="w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>
      </div>

      <p v-if="error" class="text-red-600 text-sm mt-2">
        {{ error }}
        <span v-if="confirmOverpayment"> Volve a confirmar para continuar de todos modos.</span>
      </p>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving" @click="submit">
          {{ saving ? 'Registrando...' : (confirmOverpayment ? 'Confirmar de todos modos' : 'Registrar pago') }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
