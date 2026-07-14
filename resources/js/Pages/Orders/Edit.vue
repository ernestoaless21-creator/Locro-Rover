<script setup>
/**
 * FASE 5C: edicion coherente con el nuevo alta (New.vue).
 * - Cantidad de porciones con control compacto (-/+), sigue guardando via
 *   PUT /orders/{id}/portions (debounced) sin recargar la pagina.
 * - Salsas: ya no ocupan lugar en la zona de carga rapida de porciones. Se
 *   muestran en un panel operativo aparte, mas abajo (Parte 3: tienen valor
 *   logistico en el detalle/edicion de un pedido ya guardado).
 * - "Retira en mano" invertido a checkbox "Es delivery" + direccion de
 *   entrega (instantanea propia del pedido, no pisa la del cliente).
 * - Observaciones SIEMPRE visibles.
 * - "Opciones avanzadas": solo precio excepcional para lineas NUEVAS
 *   (gateado por 'pedidos.precio-excepcional'); lineas 'regalo' historicas
 *   se siguen mostrando/editando para no romper compatibilidad, pero ya no
 *   se pueden crear nuevas desde aca (ver OrderLineForm.vue).
 */
import { Head, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrderLineForm from '@/Components/OrderLineForm.vue'
import AssignOrderModal from '@/Components/AssignOrderModal.vue'
import PayOrderModal from '@/Components/PayOrderModal.vue'
import WithdrawOrderModal from '@/Components/WithdrawOrderModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  order: { type: Object, required: true },
  years: { type: Array, required: true },
  rovers: { type: Array, default: () => [] },
  paymentMethods: { type: Array, default: () => [] },
  canAssignRover: { type: Boolean, default: false },
  canRegisterPayment: { type: Boolean, default: false },
  canWithdraw: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
  canExceptionalPrice: { type: Boolean, default: false },
  authUserId: { type: Number, default: null },
})

const toast = useToast()

function selfAssignRover() {
  router.put(`/orders/${props.order.id}`, { rover_id: props.authUserId }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Pedido autoasignado.'),
    onError: () => toast.error('No se pudo autoasignar.'),
  })
}

const order = ref({ ...props.order })
const items = ref([...props.order.items])

const locroItem = computed(() => items.value.find((i) => i.product === 'locro' && i.type === 'normal'))
const saucesItem = computed(() => items.value.find((i) => i.product === 'salsas' && i.type === 'normal'))
const advancedItems = computed(() => items.value.filter((i) => i !== locroItem.value && i !== saucesItem.value))

const portions = ref(locroItem.value?.quantity ?? 0)
const savingPortions = ref(false)

const yearId = ref(order.value.year_id)
const roverId = ref(order.value.rover_id)
const status = ref(order.value.status)
const isDelivery = ref(!order.value.take_away)
const deliveryAddress = ref(order.value.delivery_address ?? '')
const observations = ref(order.value.observations ?? '')
const savingGeneral = ref(false)
const showAdvanced = ref(false)

const editingItemId = ref(null)
const showAssignModal = ref(false)
const showPayModal = ref(false)
const showWithdrawModal = ref(false)

function incPortions() {
  portions.value = (Number(portions.value) || 0) + 1
}
function decPortions() {
  portions.value = Math.max(0, (Number(portions.value) || 0) - 1)
}

// Si se marca "Es delivery" y no hay direccion cargada todavia, precargar
// desde la direccion permanente del cliente (instantanea editable para este
// pedido, no modifica al cliente).
watch(isDelivery, (value) => {
  if (value && !deliveryAddress.value) {
    deliveryAddress.value = order.value.client?.address ?? ''
  }
})

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}

async function savePortions() {
  if (portions.value === null || portions.value < 0) return
  savingPortions.value = true
  try {
    const { data } = await axios.put(`/orders/${order.value.id}/portions`, { portions: portions.value })
    order.value = { ...order.value, ...data.order }
    items.value = data.order.items
    toast.success('Porciones actualizadas.')
  } catch (e) {
    toast.error('No se pudo actualizar la cantidad de porciones.')
  } finally {
    savingPortions.value = false
  }
}

// Debounce simple: guarda automaticamente 600ms despues de dejar de tipear.
let portionsTimer = null
watch(portions, () => {
  clearTimeout(portionsTimer)
  portionsTimer = setTimeout(savePortions, 600)
})

function saveGeneral() {
  if (isDelivery.value && !deliveryAddress.value?.trim()) {
    toast.error('Ingresa la direccion de entrega para el delivery.')
    return
  }
  savingGeneral.value = true
  router.put(
    `/orders/${order.value.id}`,
    {
      year_id: yearId.value,
      rover_id: roverId.value,
      status: status.value,
      take_away: !isDelivery.value,
      delivery_address: isDelivery.value ? deliveryAddress.value : null,
      observations: observations.value || null,
    },
    {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Datos generales guardados.')
        router.reload({ only: ['order'] })
      },
      onError: () => toast.error('No se pudo guardar. Revisa los permisos o los datos.'),
      onFinish: () => { savingGeneral.value = false },
    }
  )
}

async function addAdvancedItem(payload) {
  try {
    const { data } = await axios.post(`/orders/${order.value.id}/items`, payload)
    items.value.push(data.item)
    order.value = { ...order.value, ...data.order }
    toast.success('Excepcion agregada.')
  } catch (e) {
    toast.error('No se pudo agregar la excepcion.')
  }
}

async function updateAdvancedItem(itemId, payload) {
  try {
    const { data } = await axios.put(`/orders/${order.value.id}/items/${itemId}`, payload)
    const index = items.value.findIndex((i) => i.id === itemId)
    if (index !== -1) items.value[index] = data.item
    order.value = { ...order.value, ...data.order }
    editingItemId.value = null
    toast.success('Excepcion actualizada.')
  } catch (e) {
    toast.error('No se pudo actualizar la excepcion.')
  }
}

async function deleteAdvancedItem(itemId) {
  if (!confirm('¿Eliminar esta excepcion del pedido?')) return
  try {
    const { data } = await axios.delete(`/orders/${order.value.id}/items/${itemId}`)
    items.value = items.value.filter((i) => i.id !== itemId)
    order.value = { ...order.value, ...data.order }
    toast.success('Excepcion eliminada.')
  } catch (e) {
    toast.error('No se pudo eliminar la excepcion.')
  }
}

function onBulkActionDone() {
  router.reload({ only: ['order'] })
}

async function deleteOrder() {
  if (!confirm(`¿Eliminar el pedido #${order.value.id}? Esta accion no se puede deshacer desde aca.`)) return
  // Fase 7 (correccion 2), seccion 4: antes se usaba router.delete (Inertia),
  // que ante un 403 (usuario sin 'pedidos.eliminar') no mostraba nada: el
  // pedido seguia ahi sin ninguna explicacion. axios + try/catch permite
  // leer el mensaje real del backend y mostrarlo siempre.
  try {
    await axios.delete(`/orders/${order.value.id}`)
    toast.success('Pedido eliminado.')
    router.visit('/orders')
  } catch (e) {
    const message = e?.response?.data?.message
      || (e?.response?.status === 403
        ? 'No tenes permiso para eliminar pedidos.'
        : 'No se pudo eliminar el pedido. Intenta de nuevo.')
    toast.error(message)
  }
}
</script>

<template>
  <Head :title="`Pedido #${order.id}`" />

  <AppLayout :title="`Pedido #${order.id}`">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Pedido #{{ order.id }} — {{ order.client?.first_name }} {{ order.client?.last_name }}
      </h2>
    </template>

    <div class="py-8 max-w-2xl mx-auto px-4 space-y-6">
      <!-- Porciones (edicion rapida) -->
      <div class="bg-gray-900 text-white rounded-lg p-6 space-y-4">
        <label class="text-sm text-gray-400 block mb-1">Cantidad de porciones de locro</label>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="w-10 h-10 shrink-0 rounded-md bg-gray-800 border border-gray-600 text-lg leading-none hover:bg-gray-700"
            @click="decPortions"
          >
            −
          </button>
          <input
            v-model.number="portions"
            type="number"
            min="0"
            class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-lg text-center"
          />
          <button
            type="button"
            class="w-10 h-10 shrink-0 rounded-md bg-gray-800 border border-gray-600 text-lg leading-none hover:bg-gray-700"
            @click="incPortions"
          >
            +
          </button>
        </div>
        <p v-if="savingPortions" class="text-xs text-gray-400">Guardando...</p>

        <div class="bg-gray-800 rounded-md p-4 text-sm space-y-1">
          <div class="flex justify-between">
            <span class="text-gray-400">Precio unitario locro</span>
            <strong>{{ money(locroItem?.unit_price) }}</strong>
          </div>
          <div class="flex justify-between text-base pt-1 border-t border-gray-700 mt-1">
            <span>Total del pedido</span>
            <strong>{{ money(order.total_amount) }}</strong>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-400">Pagado</span>
            <span>{{ money(order.total_paid) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-400">Saldo</span>
            <span>{{ money(order.balance_due) }}</span>
          </div>
        </div>
      </div>

      <!-- Info operativa: salsas (Parte 3 — solo aca, no en el formulario de carga) -->
      <div class="bg-gray-900 text-white rounded-lg p-4 flex justify-between items-center text-sm">
        <span class="text-gray-400">Salsas a preparar (automático)</span>
        <strong>{{ saucesItem?.quantity ?? 0 }}</strong>
      </div>

      <!-- Datos generales -->
      <div class="bg-gray-900 text-white rounded-lg p-6 space-y-4">
        <h3 class="text-sm text-gray-400">Datos generales</h3>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="text-sm text-gray-400 block mb-1">Edicion / Año</label>
            <select v-model="yearId" class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm">
              <option v-for="y in years" :key="y.id" :value="y.id">{{ y.year }}</option>
            </select>
          </div>
          <div v-if="canAssignRover">
            <label class="text-sm text-gray-400 block mb-1">Rover responsable</label>
            <select v-model="roverId" class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm">
              <option v-for="r in rovers" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>
          <div v-else-if="!order.rover_id">
            <label class="text-sm text-gray-400 block mb-1">Responsable</label>
            <p class="text-yellow-400 text-sm mb-1">Sin asignar</p>
            <button
              type="button"
              class="bg-blue-600 hover:bg-blue-500 text-white text-xs px-3 py-1.5 rounded-md"
              @click="selfAssignRover"
            >
              Autoasignarme este pedido
            </button>
          </div>
          <div v-else>
            <label class="text-sm text-gray-400 block mb-1">Responsable</label>
            <p class="text-sm">{{ order.rover?.name ?? '—' }}</p>
          </div>
          <div>
            <label class="text-sm text-gray-400 block mb-1">Estado</label>
            <select v-model="status" class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm">
              <option value="pendiente">Pendiente</option>
              <option value="confirmado">Confirmado</option>
              <option value="cancelado">Cancelado</option>
            </select>
          </div>
        </div>

        <div>
          <div class="flex items-center gap-2">
            <input id="is_delivery" v-model="isDelivery" type="checkbox" />
            <label for="is_delivery" class="text-sm text-gray-300">Es delivery</label>
          </div>
          <p class="text-xs text-gray-500 mt-1">Sin marcar: el cliente retira en mano.</p>

          <div v-if="isDelivery" class="mt-3 space-y-1">
            <label class="text-sm text-gray-400 block mb-1">Dirección de entrega</label>
            <input
              v-model="deliveryAddress"
              type="text"
              class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm"
              placeholder="Dirección de entrega para este pedido"
            />
            <p class="text-xs text-yellow-400">
              Confirmá que la dirección de entrega sea correcta antes de guardar el pedido.
            </p>
          </div>
        </div>

        <!-- Observaciones: SIEMPRE visibles -->
        <div>
          <label class="text-sm text-gray-400 block mb-1">Observaciones del pedido</label>
          <textarea
            v-model="observations"
            rows="2"
            class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm"
            placeholder="Opcional"
          ></textarea>
        </div>

        <div class="flex justify-between items-center pt-2">
          <button
            v-if="canDelete"
            type="button"
            class="text-red-400 hover:text-red-300 text-sm"
            @click="deleteOrder"
          >
            Eliminar pedido
          </button>
          <PrimaryButton :disabled="savingGeneral" @click="saveGeneral">
            {{ savingGeneral ? 'Guardando...' : 'Guardar datos generales' }}
          </PrimaryButton>
        </div>
      </div>

      <!-- Opciones avanzadas: excepciones de precio -->
      <div v-if="canExceptionalPrice || advancedItems.length" class="bg-gray-900 text-white rounded-lg p-6">
        <button
          type="button"
          class="text-sm text-gray-400 hover:text-white flex items-center gap-1"
          @click="showAdvanced = !showAdvanced"
        >
          <span>{{ showAdvanced ? '▾' : '▸' }}</span> Opciones avanzadas (precio excepcional)
          <span v-if="advancedItems.length" class="text-xs text-gray-500">({{ advancedItems.length }})</span>
        </button>

        <div v-if="showAdvanced" class="mt-3 space-y-3">
          <div v-for="item in advancedItems" :key="item.id">
            <!-- Lineas historicas tipo 'regalo': ya no se crean nuevas desde aca
                 (ver Gift), pero se preservan editables para no romper datos
                 existentes. -->
            <div v-if="item.type === 'regalo' && editingItemId !== item.id" class="flex items-center justify-between bg-gray-800 rounded-md px-3 py-2 text-sm">
              <span>
                {{ item.quantity }}x regalo (histórico)
                <span v-if="item.description" class="text-gray-400">— {{ item.description }}</span>
              </span>
              <span class="flex items-center gap-3">
                {{ money(item.line_total) }}
                <button
                  v-if="canExceptionalPrice"
                  type="button"
                  class="text-red-400 hover:text-red-300 text-xs"
                  @click="deleteAdvancedItem(item.id)"
                >
                  Eliminar
                </button>
              </span>
            </div>
            <OrderLineForm
              v-else-if="editingItemId === item.id"
              :year-id="yearId"
              :initial="item"
              @submit="(payload) => updateAdvancedItem(item.id, payload)"
              @cancel="editingItemId = null"
            />
            <div v-else class="flex items-center justify-between bg-gray-800 rounded-md px-3 py-2 text-sm">
              <span>
                {{ item.quantity }}x {{ item.type }}
                <span v-if="item.description" class="text-gray-400">— {{ item.description }}</span>
              </span>
              <span class="flex items-center gap-3">
                {{ money(item.line_total) }}
                <button
                  v-if="canExceptionalPrice"
                  type="button"
                  class="text-blue-400 hover:text-blue-300 text-xs"
                  @click="editingItemId = item.id"
                >
                  Editar
                </button>
                <button
                  v-if="canExceptionalPrice"
                  type="button"
                  class="text-red-400 hover:text-red-300 text-xs"
                  @click="deleteAdvancedItem(item.id)"
                >
                  Eliminar
                </button>
              </span>
            </div>
          </div>

          <OrderLineForm v-if="canExceptionalPrice && editingItemId === null && yearId" :year-id="yearId" @submit="addAdvancedItem" />
        </div>
      </div>

      <!-- Pagos -->
      <div class="bg-gray-900 text-white rounded-lg p-6 space-y-2">
        <div class="flex justify-between items-center">
          <h3 class="text-sm text-gray-400">Pagos registrados</h3>
          <button
            v-if="canRegisterPayment"
            type="button"
            class="bg-blue-600 hover:bg-blue-500 px-3 py-1 rounded-md text-sm"
            @click="showPayModal = true"
          >
            Registrar pago
          </button>
        </div>
        <div v-for="p in order.payments" :key="p.id" class="text-sm text-gray-300">
          {{ money(p.amount) }} — {{ p.method?.name }} — {{ p.paid_at }}
          <span v-if="p.notes" class="text-gray-500">({{ p.notes }})</span>
        </div>
        <div v-if="!order.payments?.length" class="text-gray-500 italic text-sm">Sin pagos registrados.</div>
      </div>

      <!-- Retiro / Rover -->
      <div class="bg-gray-900 text-white rounded-lg p-6 flex flex-wrap gap-3">
        <button
          v-if="canWithdraw && order.withdrawal_status !== 'retirado'"
          type="button"
          class="bg-green-700 hover:bg-green-600 px-3 py-2 rounded-md text-sm"
          @click="showWithdrawModal = true"
        >
          Marcar como retirado
        </button>
        <span v-else-if="order.withdrawal_status === 'retirado'" class="text-sm text-green-400">
          Retirado {{ order.withdrawn_at ? `el ${order.withdrawn_at}` : '' }}
          <span v-if="order.withdrawn_by?.name"> por {{ order.withdrawn_by.name }}</span>
        </span>

        <button
          v-if="canAssignRover"
          type="button"
          class="bg-gray-700 hover:bg-gray-600 px-3 py-2 rounded-md text-sm"
          @click="showAssignModal = true"
        >
          Reasignar Rover
        </button>
      </div>
    </div>

    <AssignOrderModal
      :show="showAssignModal"
      :orders="[order]"
      :rovers="rovers"
      @close="showAssignModal = false"
      @done="onBulkActionDone"
    />
    <PayOrderModal
      :show="showPayModal"
      :orders="[order]"
      :payment-methods="paymentMethods"
      @close="showPayModal = false"
      @done="onBulkActionDone"
    />
    <WithdrawOrderModal
      :show="showWithdrawModal"
      :orders="[order]"
      @close="showWithdrawModal = false"
      @done="onBulkActionDone"
    />
  </AppLayout>
</template>
