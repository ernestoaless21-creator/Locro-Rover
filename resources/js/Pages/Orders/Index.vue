<script setup>
/**
 * Tabla principal de PEDIDOS.
 * Fase 7:
 * - seccion 9: la columna de retiro pasa a ser un checkbox simple.
 * - seccion 10: nueva accion masiva principal "Cobrar y retirar seleccionados"
 *   (resumen de saldo pendiente por pedido antes de confirmar).
 * - seccion 11: contadores compactos (porciones/salsas/mis porciones).
 * - seccion 7: observaciones visibles y editables directamente desde la tabla
 *   (Order::observations ya existia y ya era editable desde Edit.vue; lo que
 *   faltaba era poder verlas/tocarlas sin entrar a cada pedido, que es el uso
 *   real del dia de retiro/caja descripto en el prompt de la fase).
 * - seccion 4: el buscador se restaura solo al vaciarse, sin exigir Enter.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import RoverFilter from '@/Components/RoverFilter.vue'
import AssignOrderModal from '@/Components/AssignOrderModal.vue'
import PayOrderModal from '@/Components/PayOrderModal.vue'
import WithdrawOrderModal from '@/Components/WithdrawOrderModal.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  orders: { type: Object, required: true },
  year: { type: Object, required: true },
  rovers: { type: Array, default: () => [] },
  paymentMethods: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  canAssignRover: { type: Boolean, default: false },
  canRegisterPayment: { type: Boolean, default: false },
  canWithdraw: { type: Boolean, default: false },
  counters: { type: Object, default: () => ({ portions_total: 0, sauces_total: 0, my_portions: 0, gifts_count: 0, losses_count: 0 }) },
  canManageGifts: { type: Boolean, default: false },
  canManageLosses: { type: Boolean, default: false },
  // Fase 8: ranking de porciones por Rover. null = usuario sin permiso ver-todos.
  roverRanking: { type: Array, default: null },
})

// Fase 7 (correccion 4), seccion 3: formato de moneda compacto para la franja
// de recaudacion (visible solo si counters.collected viene en el payload, es
// decir, solo si el backend determino que el usuario tiene 'finanzas.ver').
const currencyFormatter = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 })
function formatCurrency(value) {
  return currencyFormatter.format(Number(value ?? 0))
}

const page = usePage()
const can = (perm) => (page.props.permissions ?? []).includes(perm)
const toast = useToast()

const search = ref(props.filters.search ?? '')
const suggestions = ref([])
const showSuggestions = ref(false)
const suggesting = ref(false)
let suggestDebounce = null
const selected = ref(new Set())
const showAssignModal = ref(false)
const showPayModal = ref(false)
const showWithdrawModal = ref(false)
const showPayAndWithdrawModal = ref(false)
const payAndWithdrawMethod = ref(props.paymentMethods[0]?.id ?? '')
const payAndWithdrawBusy = ref(false)
const withdrawBusyIds = ref(new Set())

function reloadWith(extra) {
  router.get('/orders', { ...props.filters, ...extra, year_id: props.year.id }, {
    preserveState: true,
    replace: true,
  })
}

function applySearch() {
  reloadWith({ search: search.value || undefined })
}

// Fase 7, seccion 4: al borrar completamente el buscador, la lista se
// restaura automaticamente (antes habia que apretar Enter con el campo vacio).
let debounceHandle = null
watch(search, (value) => {
  if (value !== '') return
  clearTimeout(debounceHandle)
  debounceHandle = setTimeout(() => applySearch(), 150)
})

// Fase 7 (correccion 3): sugerencias/autocompletado en tiempo real mientras
// se escribe, igual que en Clients/Index.vue (mismo endpoint /clients/search,
// mismo debounce, mismo criterio de busqueda tolerante via
// Client::scopeSearchTerm — nombre, apellido, nombre+apellido en cualquier
// orden, telefono y N° historico). No se duplica backend: se reutiliza el
// endpoint tal cual.
watch(search, (value) => {
  clearTimeout(suggestDebounce)
  const term = value.trim()
  if (term.length < 2) {
    suggestions.value = []
    showSuggestions.value = false
    return
  }
  suggestDebounce = setTimeout(async () => {
    suggesting.value = true
    try {
      const { data } = await axios.get('/clients/search', { params: { q: term } })
      suggestions.value = data
      showSuggestions.value = true
    } catch (e) {
      // Fase 7 (correccion 3): manejo explicito de error de red — no dejar
      // el dropdown en un estado indefinido ni romper el buscador normal.
      suggestions.value = []
      showSuggestions.value = false
    } finally {
      suggesting.value = false
    }
  }, 250)
})

function pickSuggestion(client) {
  showSuggestions.value = false
  suggestions.value = []
  // El N° historico es unico: filtra exactamente a ESE cliente (y, si tiene
  // varios pedidos en esta edicion/año, a TODOS ellos), sin ambiguedad de
  // nombres repetidos. Los demas filtros (rover, retiro, pago, año) se
  // preservan tal cual via reloadWith/applySearch.
  search.value = String(client.historical_number ?? `${client.first_name} ${client.last_name}`)
  applySearch()
}

function toggleSelected(id) {
  if (selected.value.has(id)) selected.value.delete(id)
  else selected.value.add(id)
  selected.value = new Set(selected.value)
}

const allSelected = computed(() =>
  props.orders.data.length > 0 && props.orders.data.every((o) => selected.value.has(o.id))
)

function toggleSelectAll() {
  selected.value = allSelected.value
    ? new Set()
    : new Set(props.orders.data.map((o) => o.id))
}

const selectedOrders = computed(() =>
  props.orders.data.filter((o) => selected.value.has(o.id))
)

const payAndWithdrawSummary = computed(() => {
  const items = selectedOrders.value
  const totalPortions = items.reduce((sum, o) => sum + Number(o.total_portions ?? 0), 0)
  const totalToCharge = items.reduce((sum, o) => sum + Number(o.balance_due ?? 0), 0)
  return { items, totalPortions, totalToCharge, count: items.length }
})

function onBulkActionDone() {
  selected.value = new Set()
  router.reload({ only: ['orders', 'counters'] })
}

async function confirmPayAndWithdraw() {
  if (!payAndWithdrawMethod.value || selectedOrders.value.length === 0) return
  payAndWithdrawBusy.value = true
  try {
    const { data } = await axios.post('/orders/bulk-pay-and-withdraw', {
      order_ids: selectedOrders.value.map((o) => o.id),
      payment_method_id: payAndWithdrawMethod.value,
    })
    toast.success(`${data.withdrawn} pedido(s) retirados, ${data.payments_created} pago(s) registrados.`)
    showPayAndWithdrawModal.value = false
    onBulkActionDone()
  } catch (e) {
    toast.error('No se pudo completar la accion. Verifica tus permisos e intenta de nuevo.')
  } finally {
    payAndWithdrawBusy.value = false
  }
}

async function toggleWithdrawn(order, checked) {
  withdrawBusyIds.value.add(order.id)
  withdrawBusyIds.value = new Set(withdrawBusyIds.value)
  try {
    await axios.post(checked ? '/orders/bulk-withdraw' : '/orders/bulk-unwithdraw', {
      order_ids: [order.id],
    })
    toast.success(checked ? 'Pedido marcado como retirado.' : 'Retiro desmarcado.')
    router.reload({ only: ['orders'] })
  } catch (e) {
    toast.error('No se pudo actualizar el retiro (verifica tus permisos).')
  } finally {
    withdrawBusyIds.value.delete(order.id)
    withdrawBusyIds.value = new Set(withdrawBusyIds.value)
  }
}

function saveObservations(order, value) {
  router.put(`/orders/${order.id}`, { observations: value || null }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => toast.success('Observacion guardada.'),
    onError: () => toast.error('No se pudo guardar la observacion.'),
  })
}

// Fase 8: ancho de barra proporcional al rover con más porciones (100%).
const maxPortions = computed(() => {
  if (!props.roverRanking || props.roverRanking.length === 0) return 1
  return Math.max(...props.roverRanking.map((r) => r.total_portions), 1)
})

function barWidth(portions) {
  return Math.round((portions / maxPortions.value) * 100)
}

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}

function paymentStatus(order) {
  if (Number(order.total_paid) <= 0) return { label: 'Pendiente', cls: 'bg-gray-700' }
  if (Number(order.total_paid) >= Number(order.total_amount)) return { label: 'Pagado', cls: 'bg-green-700' }
  return { label: 'Parcial', cls: 'bg-yellow-700' }
}

function sauces(order) {
  return order.items?.find((i) => i.product === 'salsas' && i.type === 'normal')?.quantity ?? 0
}
</script>

<template>
  <Head title="Pedidos" />

  <AppLayout title="Pedidos">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pedidos — Edicion {{ year.year }}</h2>
        <Link
          v-if="can('pedidos.crear')"
          href="/orders/create"
          class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-md text-sm"
        >
          + Nuevo pedido
        </Link>
      </div>
    </template>

    <div class="py-6 max-w-7xl mx-auto px-4">
      <div class="mb-4">
        <YearSelector :selected-year-id="year.id" />
      </div>

      <!-- Fase 7 (correccion 4), secciones 2, 3 y 5: franja compacta de
           indicadores. Una sola linea en escritorio, wrap en movil; nada de
           tarjetas grandes de Dashboard. -->
      <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-2 text-sm text-gray-700">
        <span>🍲 {{ counters.portions_total }} porciones</span>
        <span class="text-gray-500">·</span>
        <span>🌶️ {{ counters.sauces_total }} salsas</span>
        <span class="text-gray-500">·</span>
        <span>👤 {{ counters.my_portions }} mías</span>
        <span class="text-gray-500">·</span>
        <Link v-if="canManageGifts" :href="`/gifts?year_id=${year.id}`" class="hover:text-gray-900 hover:underline" title="Ver regalos">
          🎁 {{ counters.gifts_count }}
        </Link>
        <span v-else title="Regalos registrados">🎁 {{ counters.gifts_count }}</span>
        <span class="text-gray-500">·</span>
        <Link v-if="canManageLosses" :href="`/losses?year_id=${year.id}`" class="hover:text-gray-900 hover:underline" title="Ver pérdidas">
          ⚠️ {{ counters.losses_count }}
        </Link>
        <span v-else title="Pérdidas registradas">⚠️ {{ counters.losses_count }}</span>
      </div>

      <!-- Fase 7 (correccion 4), seccion 3: recaudacion compacta, SOLO si el
           backend mando counters.collected (es decir, solo con 'finanzas.ver';
           un usuario sin ese permiso ni siquiera recibe este dato). -->
      <div v-if="counters.collected" class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-4 text-sm text-gray-700">
        <span>💰 Recaudado: <strong class="text-gray-900">{{ formatCurrency(counters.collected.total) }}</strong></span>
        <span class="text-gray-500">·</span>
        <span>💵 {{ formatCurrency(counters.collected.efectivo) }} efectivo</span>
        <span class="text-gray-500">·</span>
        <span>🏦 {{ formatCurrency(counters.collected.banco) }} transferencia</span>
      </div>
      <div v-else class="mb-2"></div>

      <!-- Fase 8: gráfico de porciones por Rover. Solo visible con permiso
           'pedidos.ver-todos' (el backend no envía roverRanking a usuarios
           sin ese permiso). Sin librería de gráficos: barras CSS puras. -->
      <div v-if="roverRanking && roverRanking.length > 0" class="mb-4">
        <p class="text-xs font-semibold text-gray-600 mb-1.5">📊 Porciones por Rover</p>
        <div class="space-y-1 max-w-md">
          <div
            v-for="entry in roverRanking"
            :key="entry.rover_id"
            class="flex items-center gap-2 text-xs"
          >
            <span class="w-28 text-right text-gray-600 truncate shrink-0">{{ entry.name }}</span>
            <div class="flex-1 bg-gray-200 rounded h-4 overflow-hidden min-w-0">
              <div
                class="bg-blue-500 h-4 rounded transition-all duration-300"
                :style="{ width: barWidth(entry.total_portions) + '%' }"
              />
            </div>
            <span class="shrink-0 text-gray-700 font-medium w-8 text-left">{{ entry.total_portions }}</span>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 mb-4 items-center">
        <div class="relative">
          <input
            v-model="search"
            type="text"
            placeholder="Buscar cliente (nombre, apellido, telefono, N° histórico)..."
            class="bg-gray-800 text-white border border-gray-600 rounded-md px-3 py-2 text-sm w-72"
            @keydown.enter="applySearch"
            @focus="showSuggestions = suggestions.length > 0"
            @blur="() => setTimeout(() => (showSuggestions = false), 150)"
          />
          <!-- Fase 7 (correccion 3): sugerencias en tiempo real (sin apretar Enter),
               mismo patron que Clients/Index.vue. -->
          <div
            v-if="showSuggestions && (suggestions.length || suggesting)"
            class="absolute z-10 mt-1 w-72 bg-gray-800 border border-gray-700 rounded-md shadow-lg max-h-64 overflow-y-auto"
          >
            <div v-if="suggesting" class="px-3 py-2 text-xs text-gray-400">Buscando...</div>
            <button
              v-for="s in suggestions"
              :key="s.id"
              type="button"
              class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-700"
              @mousedown.prevent="pickSuggestion(s)"
            >
              <span class="text-gray-500 mr-1">#{{ s.historical_number ?? '—' }}</span>
              {{ s.last_name }}, {{ s.first_name }}
              <span class="text-gray-400 text-xs">— {{ s.phone || 'sin teléfono' }}</span>
            </button>
            <div v-if="!suggesting && !suggestions.length" class="px-3 py-2 text-xs text-gray-400">
              Sin resultados.
            </div>
          </div>
        </div>
        <button class="bg-blue-600 hover:bg-blue-500 text-white px-3 py-2 rounded-md text-sm" @click="applySearch">
          Buscar
        </button>

        <RoverFilter :rovers="rovers" :model-value="filters.rover_id ?? 'all'" />

        <select
          class="bg-gray-800 text-white border border-gray-600 rounded-md px-2 py-2 text-sm"
          :value="filters.withdrawal_status ?? ''"
          @change="reloadWith({ withdrawal_status: $event.target.value || undefined })"
        >
          <option value="">Retiro: todos</option>
          <option value="no_retirado">No retirado</option>
          <option value="parcial">Parcial</option>
          <option value="retirado">Retirado</option>
        </select>

        <select
          class="bg-gray-800 text-white border border-gray-600 rounded-md px-2 py-2 text-sm"
          :value="filters.payment_status ?? ''"
          @change="reloadWith({ payment_status: $event.target.value || undefined })"
        >
          <option value="">Pago: todos</option>
          <option value="pendiente">Pendiente</option>
          <option value="parcial">Parcial</option>
          <option value="pagado">Pagado</option>
        </select>

        <!-- Fase 7 (correccion 4), seccion 4: filtro Delivery/Retiro para
             organizar los recorridos del dia. take_away=true es "retira en
             mano" y take_away=false es delivery (se respeta esa semantica
             real, ver OrderController::index). reloadWith ya preserva el
             resto de los filtros (año, rover, retiro, pago, busqueda). -->
        <select
          class="bg-gray-800 text-white border border-gray-600 rounded-md px-2 py-2 text-sm"
          :value="filters.delivery_type ?? ''"
          @change="reloadWith({ delivery_type: $event.target.value || undefined })"
        >
          <option value="">Entrega: todos</option>
          <option value="delivery">Delivery</option>
          <option value="retiro">Retiro</option>
        </select>

        <!-- Fase 8: filtros rápidos personales. Son toggles: activos se
             resaltan, inactivos tienen el mismo estilo que los selects. -->
        <button
          class="px-3 py-2 rounded-md text-sm border transition-colors"
          :class="filters.my_assigned_clients
            ? 'bg-indigo-600 text-white border-indigo-500'
            : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
          @click="reloadWith({ my_assigned_clients: filters.my_assigned_clients ? undefined : '1' })"
        >
          Mis clientes asignados
        </button>

        <button
          class="px-3 py-2 rounded-md text-sm border transition-colors"
          :class="filters.created_by_me
            ? 'bg-purple-600 text-white border-purple-500'
            : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
          @click="reloadWith({ created_by_me: filters.created_by_me ? undefined : '1' })"
        >
          Mis pedidos cargados
        </button>
      </div>

      <!-- Acciones masivas -->
      <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-2 mb-3 bg-gray-800 text-white rounded-md px-3 py-2 text-sm">
        <span>{{ selected.size }} seleccionado(s)</span>

        <!-- Fase 7, seccion 10: accion principal destacada. -->
        <button
          v-if="canRegisterPayment && canWithdraw"
          class="bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 rounded-md font-semibold"
          @click="showPayAndWithdrawModal = true"
        >
          Cobrar y retirar seleccionados
        </button>

        <button v-if="canAssignRover" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded-md" @click="showAssignModal = true">
          Asignar Rover
        </button>
        <button v-if="canRegisterPayment" class="bg-blue-600 hover:bg-blue-500 px-3 py-1 rounded-md" @click="showPayModal = true">
          Registrar pago
        </button>
        <!-- Accion secundaria: se mantiene para casos especiales (ver seccion 10). -->
        <button v-if="canWithdraw" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded-md" @click="showWithdrawModal = true">
          Marcar retirado (solo)
        </button>
      </div>

      <div class="overflow-x-auto rounded-md border border-gray-700">
        <table class="w-full text-sm bg-gray-900 text-white">
          <thead class="bg-gray-800">
            <tr>
              <th class="p-2 w-8">
                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
              </th>
              <th class="p-2 text-left">Cliente</th>
              <th class="p-2 text-left">Rover</th>
              <th class="p-2 text-left">Porciones</th>
              <th class="p-2 text-left">Salsas</th>
              <th class="p-2 text-left">Entrega</th>
              <th class="p-2 text-left">Importe</th>
              <th class="p-2 text-left">Saldo</th>
              <th class="p-2 text-left">Pago</th>
              <th class="p-2 text-left">Retirado</th>
              <th class="p-2 text-left">Observaciones</th>
              <th class="p-2 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="order in orders.data"
              :key="order.id"
              class="border-t border-gray-800 hover:bg-gray-800/60"
            >
              <td class="p-2">
                <input type="checkbox" :checked="selected.has(order.id)" @change="toggleSelected(order.id)" />
              </td>
              <td class="p-2">{{ order.client?.first_name }} {{ order.client?.last_name }}</td>
              <td class="p-2">{{ order.rover?.name ?? '-' }}</td>
              <td class="p-2">{{ order.total_portions }}</td>
              <td class="p-2">{{ sauces(order) }}</td>
              <td class="p-2">
                <span class="px-2 py-0.5 rounded-full text-xs" :class="order.take_away ? 'bg-gray-700' : 'bg-blue-700'">
                  {{ order.take_away ? 'Retiro en mano' : 'Delivery' }}
                </span>
                <!-- Fase 7 (correccion 4), seccion 4: para pedidos de
                     Delivery, la direccion queda SIEMPRE visible (no solo
                     con el filtro aplicado, para no esconder informacion
                     util en la vista "todos" tambien). Se reutiliza
                     order.delivery_address tal cual (instantanea propia del
                     pedido, ver migracion 2026_07_13_000004): no se inventa
                     un fallback a la direccion del cliente, que a proposito
                     es un dato distinto. Si no hay direccion utilizable, se
                     marca claramente en vez de dejarlo en blanco. -->
                <div v-if="!order.take_away" class="text-xs mt-0.5" :class="order.delivery_address ? 'text-gray-400' : 'text-amber-400 font-semibold'">
                  {{ order.delivery_address || '⚠ Sin dirección' }}
                </div>
              </td>
              <td class="p-2">{{ money(order.total_amount) }}</td>
              <td class="p-2">{{ money(order.balance_due) }}</td>
              <td class="p-2">
                <span class="px-2 py-0.5 rounded-full text-xs" :class="paymentStatus(order).cls">
                  {{ paymentStatus(order).label }}
                </span>
              </td>
              <td class="p-2">
                <!-- Fase 7, seccion 9: checkbox simple. 'parcial' (heredado de
                     retiros divididos entre varias personas) se muestra como
                     una etiqueta aparte, ya que no es un simple si/no; el
                     checkbox para ese caso queda sin marcar (no es "retirado"
                     completo) y tocarlo lo lleva a retirado total. -->
                <div class="flex items-center gap-1.5">
                  <input
                    type="checkbox"
                    :disabled="!canWithdraw || withdrawBusyIds.has(order.id)"
                    :checked="order.withdrawal_status === 'retirado'"
                    @change="toggleWithdrawn(order, $event.target.checked)"
                  />
                  <span v-if="order.withdrawal_status === 'parcial'" class="text-xs text-yellow-400">parcial</span>
                </div>
                <div v-if="order.withdrawn_by" class="text-[10px] text-gray-500 mt-0.5">
                  {{ order.withdrawn_by.name }}
                </div>
              </td>
              <td class="p-2 min-w-[10rem]">
                <input
                  type="text"
                  :value="order.observations"
                  placeholder="Ej: retira la hermana..."
                  class="bg-gray-800 border border-gray-600 rounded-md px-1.5 py-1 text-xs w-full"
                  @change="saveObservations(order, $event.target.value)"
                />
                <!-- Fase 7 (correccion 2), seccion 3: nota de seguimiento del
                     cliente en esta edicion (ClientAssignment.notes, la misma
                     que se edita desde /clients). Es de SOLO LECTURA aca a
                     proposito: es un campo distinto (seguimiento/pre-venta,
                     no logistica de este pedido puntual), se muestra para dar
                     contexto sin fusionar dos conceptos distintos. -->
                <p v-if="order.client_assignment_notes" class="text-[10px] text-gray-500 mt-0.5 italic">
                  Seguimiento: {{ order.client_assignment_notes }}
                </p>
              </td>
              <td class="p-2">
                <Link :href="`/orders/${order.id}/edit`" class="text-blue-400 hover:text-blue-300">
                  Editar
                </Link>
              </td>
            </tr>
            <tr v-if="!orders.data.length">
              <td colspan="12" class="p-6 text-center text-gray-500">
                No hay pedidos para este filtro.
                <Link v-if="can('pedidos.crear')" href="/orders/create" class="text-blue-400 block mt-1">
                  Crear el primer pedido
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap gap-2 mt-4">
        <Link
          v-for="link in orders.links"
          :key="link.label"
          :href="link.url ?? '#'"
          v-html="link.label"
          class="px-3 py-1 rounded-md text-sm"
          :class="[
            link.active ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700',
            !link.url ? 'opacity-40 pointer-events-none' : 'hover:bg-gray-300',
          ]"
        />
      </div>
    </div>

    <AssignOrderModal
      :show="showAssignModal"
      :orders="selectedOrders"
      :rovers="rovers"
      @close="showAssignModal = false"
      @done="onBulkActionDone"
    />
    <PayOrderModal
      :show="showPayModal"
      :orders="selectedOrders"
      :payment-methods="paymentMethods"
      @close="showPayModal = false"
      @done="onBulkActionDone"
    />
    <WithdrawOrderModal
      :show="showWithdrawModal"
      :orders="selectedOrders"
      @close="showWithdrawModal = false"
      @done="onBulkActionDone"
    />

    <!-- Fase 7, seccion 10: confirmacion detallada de "Cobrar y retirar". -->
    <div v-if="showPayAndWithdrawModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div class="bg-gray-900 text-white rounded-lg p-6 max-w-lg w-full space-y-4">
        <h3 class="font-semibold text-lg">Cobrar y retirar seleccionados</h3>

        <div class="max-h-64 overflow-y-auto divide-y divide-gray-800 text-sm">
          <div v-for="o in payAndWithdrawSummary.items" :key="o.id" class="py-2 flex justify-between">
            <div>
              <p class="font-medium">{{ o.client?.first_name }} {{ o.client?.last_name }}</p>
              <p class="text-xs text-gray-400">{{ o.total_portions }} porciones</p>
            </div>
            <p class="text-sm" :class="Number(o.balance_due) > 0 ? 'text-yellow-400' : 'text-green-400'">
              {{ Number(o.balance_due) > 0 ? `Saldo: ${money(o.balance_due)}` : 'Sin saldo' }}
            </p>
          </div>
        </div>

        <div class="bg-gray-800 rounded-md p-3 text-sm space-y-1">
          <p>TOTAL: {{ payAndWithdrawSummary.count }} pedido(s)</p>
          <p>{{ payAndWithdrawSummary.totalPortions }} porciones</p>
          <p class="font-semibold">{{ money(payAndWithdrawSummary.totalToCharge) }} a cobrar</p>
        </div>

        <div>
          <label class="text-xs text-gray-400 block mb-1">Medio de pago para lo cobrado</label>
          <select v-model="payAndWithdrawMethod" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1.5 text-sm w-full">
            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">{{ pm.name }}</option>
          </select>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-md text-sm" @click="showPayAndWithdrawModal = false">
            Cancelar
          </button>
          <button
            type="button"
            :disabled="payAndWithdrawBusy || !payAndWithdrawMethod"
            class="bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-3 py-1.5 rounded-md text-sm font-semibold"
            @click="confirmPayAndWithdraw"
          >
            Confirmar
          </button>
        </div>
      </div>
    </div>

    <ToastContainer />
  </AppLayout>
</template>
