<script setup>
/**
 * Registra pagos (payments reales, nunca un booleano) para uno o varios
 * pedidos. Fase 18.1: una unica interfaz (antes habia un toggle "Pagar saldo
 * total" / "Montos especificos", eliminado). Siempre se cargan una o mas
 * lineas {medio de pago, monto}, aplicadas identicas a cada pedido
 * seleccionado (soporta pago parcial y varios medios a la vez, ej: $10.000
 * efectivo + $5.000 transferencia). Si hay un solo pedido seleccionado, el
 * primer monto se precarga con su saldo pendiente como ayuda (editable).
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
const lines = ref([{ payment_method_id: null, amount: null }])
const paidAt = ref(new Date().toISOString().slice(0, 10))
const notes = ref('')
const saving = ref(false)
const error = ref(null)

watch(() => props.show, (visible) => {
  if (!visible) return
  lines.value = [{
    payment_method_id: props.paymentMethods[0]?.id ?? null,
    // Ayuda: con un solo pedido seleccionado, precarga su saldo pendiente
    // (el usuario lo puede editar libremente, no es un modo aparte).
    amount: props.orders.length === 1 && Number(props.orders[0].balance_due) > 0
      ? Number(props.orders[0].balance_due)
      : null,
  }]
  paidAt.value = new Date().toISOString().slice(0, 10)
  notes.value = ''
  error.value = null
})

const totalBalance = computed(() => props.orders.reduce((sum, o) => sum + Number(o.balance_due), 0))
const linesTotal = computed(() => lines.value.reduce((sum, l) => sum + (Number(l.amount) || 0), 0))

// Fase 18.1: estado de sobrepago SIEMPRE visible mientras se completa el
// formulario (no solo como error al intentar guardar). Con un solo pedido,
// el monto exacto a devolver; con varios, se avisa que aplica a cada uno
// (las lineas se aplican identicas a cada pedido seleccionado, ver docblock
// de arriba y OrderBulkController::pay).
const overpayAmount = computed(() => {
  if (props.orders.length === 1) {
    return linesTotal.value - Number(props.orders[0].balance_due)
  }
  const anyOverpay = props.orders.some((o) => linesTotal.value > Number(o.balance_due))
  return anyOverpay ? linesTotal.value : 0
})

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
    mode: 'fixed_lines',
    lines: lines.value.filter((l) => l.payment_method_id && l.amount > 0),
    paid_at: paidAt.value,
    notes: notes.value || null,
    // Fase 18.1: el sobrepago ya se muestra en vivo en el propio formulario
    // (ver overpayAmount), asi que no hace falta un segundo paso de
    // confirmacion: si el usuario llego a guardar, ya lo vio.
    confirm_overpayment: true,
  }

  try {
    const { data } = await axios.post('/orders/bulk-pay', payload)
    toast.success(`${data.payments_created} pago(s) registrado(s).`)
    emit('done')
    emit('close')
  } catch (e) {
    error.value = 'No se pudo registrar el pago.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :show="show" @close="$emit('close')">
    <div class="p-5">
      <h2 class="text-lg font-medium text-gray-900 mb-2">Registrar pago</h2>
      <p class="text-sm text-gray-600 mb-4">
        {{ orders.length }} pedido(s) — saldo total pendiente: {{ money(totalBalance) }}
      </p>

      <div class="space-y-2">
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

      <!-- Fase 18.1: estado de sobrepago, siempre visible mientras se completa (no un error posterior). -->
      <p v-if="overpayAmount > 0" class="text-red-600 text-sm font-semibold mt-3">
        Sobrepago<span v-if="orders.length === 1">: debe devolver {{ money(overpayAmount) }}</span>
        <span v-else>: el monto supera el saldo de al menos un pedido seleccionado.</span>
      </p>

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

      <p v-if="error" class="text-red-600 text-sm mt-2">{{ error }}</p>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving" @click="submit">
          {{ saving ? 'Registrando...' : 'Registrar pago' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
