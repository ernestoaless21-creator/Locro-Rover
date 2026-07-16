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
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import RoverFilter from '@/Components/RoverFilter.vue'
import Modal from '@/Components/Modal.vue'
import AssignOrderModal from '@/Components/AssignOrderModal.vue'
import PayOrderModal from '@/Components/PayOrderModal.vue'
import WithdrawOrderModal from '@/Components/WithdrawOrderModal.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import EmptyState from '@/Components/EmptyState.vue'
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
  counters: { type: Object, default: () => ({ portions_total: 0, sauces_total: 0, my_portions: 0, gifts_count: 0, losses_count: 0, portions_pending_withdrawal: 0, portions_withdrawn: 0 }) },
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

// Fase 18: filtros colapsables detras de un boton "Filtros". La busqueda
// principal (search) siempre queda visible; el panel arranca abierto si ya
// hay algun filtro avanzado aplicado, para no esconder un estado activo.
const hasAdvancedFilters = computed(() => Boolean(
  (props.filters.rover_id && props.filters.rover_id !== 'all') ||
  props.filters.withdrawal_status ||
  props.filters.payment_status ||
  props.filters.delivery_type ||
  props.filters.my_assigned_clients ||
  props.filters.my_sales
))
const activeFilterCount = computed(() => [
  props.filters.rover_id && props.filters.rover_id !== 'all',
  props.filters.withdrawal_status,
  props.filters.payment_status,
  props.filters.delivery_type,
  props.filters.my_assigned_clients,
  props.filters.my_sales,
].filter(Boolean).length)
const showFilters = ref(hasAdvancedFilters.value)
const isFiltering = computed(() => Boolean(props.filters.search || hasAdvancedFilters.value))

// Fase 18: si el estado vacio es producto de una busqueda con texto, el
// mensaje aprovecha ese contexto (nombre buscado). Sin busqueda, se
// mantiene el mensaje general de siempre -- mismo criterio pensado para
// reutilizarse en otros modulos (Clientes, Compras, etc.), cambiando solo
// el texto de "pedidos" por el que corresponda.
const emptyOrdersDescription = computed(() => {
  const term = props.filters.search
  return term
    ? `No encontramos pedidos para "${term}". Probá cambiando los filtros o verificando el nombre.`
    : 'Probá cambiando los filtros o... ¡salí a vender un poco más! 🍲'
})

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

// Fase 18 (microinteracciones): foco automatico en el buscador principal al
// entrar a la pantalla (tambien cubre "volver al buscador" despues de crear
// un pedido, ya que New.vue redirige aca). Y retorno de foco al elemento que
// abrio un modal, al cerrarlo (accesibilidad de teclado).
const searchInput = ref(null)
onMounted(() => searchInput.value?.focus())

const lastTrigger = ref(null)
function rememberTrigger(e) {
  lastTrigger.value = e.currentTarget
}
function returnFocus() {
  nextTick(() => lastTrigger.value?.focus())
}

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
    returnFocus()
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

// Fase 8 / Fase 9 (ampliacion): gráfico de ventas por Rover con colores progresivos y línea de meta.
const salesGoal = computed(() => props.year?.sales_goal_individual_default ?? 0)

const maxPortions = computed(() => {
  if (!props.roverRanking || props.roverRanking.length === 0) return 1
  const maxRover = Math.max(...props.roverRanking.map((r) => r.total_portions), 1)
  return salesGoal.value > 0 ? Math.max(maxRover, salesGoal.value) : maxRover
})

function barWidth(portions) {
  return Math.round((portions / maxPortions.value) * 100)
}

function barColor(portions) {
  if (!salesGoal.value) return 'hsl(220, 65%, 55%)'
  const pct = Math.min(portions / salesGoal.value, 1)
  return `hsl(${Math.round(pct * 120)}, 65%, 45%)`
}

// Fase 18: medallas para el podio del ranking (roverRanking ya viene
// ordenado desc por total_portions desde el backend, ver OrderController::roverRanking).
const rankIcons = ['🥇', '🥈', '🥉']
function rankBadge(index) {
  return rankIcons[index] ?? `${index + 1}º`
}

// Fase 18.1: con muchos Rovers (20+) una sola columna se vuelve demasiado
// larga arriba de la tabla, que es lo importante de esta pantalla. En vez de
// partir en dos columnas (mas ancho, mas dificil de leer en orden), se
// muestra el Top 10 y se revela el resto con un boton, mismo patron de
// "revelar mas" que ya usan Filtros/Opciones avanzadas en esta pantalla.
const showFullRanking = ref(false)
const visibleRanking = computed(() => {
  if (!props.roverRanking) return []
  return showFullRanking.value ? props.roverRanking : props.roverRanking.slice(0, 10)
})

// Fase 8 (correccion): filtros de retiro clickeables mutuamente excluyentes.
// Se usan nombres distintos de toggleWithdrawn (que ya existe para el checkbox
// de fila individual) para evitar colision de nombres.
function filterByPendingWithdrawal() {
  reloadWith({
    pending_withdrawal: props.filters.pending_withdrawal ? undefined : '1',
    withdrawn: undefined,
  })
}

function filterByWithdrawn() {
  reloadWith({
    withdrawn: props.filters.withdrawn ? undefined : '1',
    pending_withdrawal: undefined,
  })
}

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}

// Fase 18: la columna "Estado" usa "Sin pagar" en vez de "Pendiente" (esa
// palabra ya la usa la columna de monto). Se probo el semaforo de emojis
// visualmente y se decidio texto + color del badge, sin emoji (menos ruido
// visual). Los valores internos (total_paid vs total_amount) y las clases
// de color de fondo no cambian -- solo el texto.
function paymentStatus(order) {
  if (Number(order.total_paid) <= 0) return { label: 'Sin pagar', cls: 'bg-gray-700' }
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
      <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        <h2 class="font-semibold text-xl text-white leading-tight">Pedidos — Edicion {{ year.year }}</h2>
        <Link
          v-if="can('pedidos.crear')"
          href="/orders/create"
          class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold"
        >
          + Nuevo pedido
        </Link>
      </div>
    </template>

    <div class="py-4 max-w-7xl mx-auto px-4">
      <div class="space-y-3 mb-4">
        <YearSelector :selected-year-id="year.id" />

        <!-- Fase 18 (ajuste fino): franja de métricas como chips discretos,
             reutilizando el mismo patrón visual que los botones de filtro de
             esta pantalla (rectangular = clickeable, redondeado/pill = solo
             lectura, mismo radio que Badge). Los nuevos (por retirar,
             retiradas, vendidas por mí) son clickeables: aplican el filtro
             correspondiente en la tabla. Acento de color (maize/herb) SOLO en
             los dos indicadores realmente operativos del día; el resto queda
             neutro para no competir por atención. -->
        <div class="flex flex-wrap gap-1.5">
          <span class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300">
            🍗 <span class="font-semibold text-white">{{ counters.portions_total }}</span> vendidas
          </span>
          <span class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300">
            🌶️ <span class="font-semibold text-white">{{ counters.sauces_total }}</span> salsas
          </span>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium border transition-colors"
            :class="filters.pending_withdrawal ? 'bg-surface-3 text-white border-gray-500' : 'bg-surface text-gray-300 border-border-soft hover:bg-surface-3'"
            title="Filtrar pedidos por retirar"
            @click="filterByPendingWithdrawal"
          >
            🕐 <span class="font-semibold text-maize">{{ counters.portions_pending_withdrawal }}</span> por retirar
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium border transition-colors"
            :class="filters.withdrawn ? 'bg-surface-3 text-white border-gray-500' : 'bg-surface text-gray-300 border-border-soft hover:bg-surface-3'"
            title="Filtrar pedidos retirados"
            @click="filterByWithdrawn"
          >
            ✅ <span class="font-semibold text-herb">{{ counters.portions_withdrawn }}</span> retiradas
          </button>
          <span class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300" title="Porciones producidas (configurado en Parámetros)">
            📦 <span class="font-semibold text-white">{{ year.made_portions ?? 0 }}</span> producidas
          </span>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium border transition-colors"
            :class="filters.my_sales ? 'bg-surface-3 text-white border-gray-500' : 'bg-surface text-gray-300 border-border-soft hover:bg-surface-3'"
            title="Filtrar mis ventas"
            @click="reloadWith({ my_sales: filters.my_sales ? undefined : '1' })"
          >
            👤 <span class="font-semibold text-white">{{ counters.my_portions }}</span> vendidas por mí
          </button>
          <Link v-if="canManageGifts" :href="`/gifts?year_id=${year.id}`" class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300 hover:text-white hover:border-gray-500" title="Ver regalos">
            🎁 <span class="font-semibold text-white">{{ counters.gifts_count }}</span>
          </Link>
          <span v-else class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300" title="Regalos registrados">
            🎁 <span class="font-semibold text-white">{{ counters.gifts_count }}</span>
          </span>
          <Link v-if="canManageLosses" :href="`/losses?year_id=${year.id}`" class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300 hover:text-white hover:border-gray-500" title="Ver pérdidas">
            🗑️ <span class="font-semibold text-white">{{ counters.losses_count }}</span>
          </Link>
          <span v-else class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300" title="Pérdidas registradas">
            🗑️ <span class="font-semibold text-white">{{ counters.losses_count }}</span>
          </span>
        </div>

        <!-- Recaudacion compacta, SOLO con 'finanzas.ver'. Efectivo y
             transferencia son clickeables: filtran por medio de pago (AND
             cuando ambos activos). -->
        <div v-if="counters.collected" class="flex flex-wrap gap-1.5">
          <span class="inline-flex items-center gap-1 bg-surface border border-border-soft rounded-full px-1.5 py-0.5 text-xs font-medium text-gray-300">
            💰 Recaudado <span class="font-semibold text-white">{{ formatCurrency(counters.collected.total) }}</span>
          </span>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium border transition-colors"
            :class="filters.pay_efectivo ? 'bg-surface-3 text-white border-gray-500' : 'bg-surface text-gray-300 border-border-soft hover:bg-surface-3'"
            title="Filtrar pedidos con pago en efectivo"
            @click="reloadWith({ pay_efectivo: filters.pay_efectivo ? undefined : '1' })"
          >
            💵 <span class="font-semibold text-white">{{ formatCurrency(counters.collected.efectivo) }}</span> efectivo
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium border transition-colors"
            :class="filters.pay_transferencia ? 'bg-surface-3 text-white border-gray-500' : 'bg-surface text-gray-300 border-border-soft hover:bg-surface-3'"
            title="Filtrar pedidos con pago en transferencia"
            @click="reloadWith({ pay_transferencia: filters.pay_transferencia ? undefined : '1' })"
          >
            🏦 <span class="font-semibold text-white">{{ formatCurrency(counters.collected.banco) }}</span> transferencia
          </button>
        </div>

        <!-- Fase 8 / Fase 9 (ampliacion): gráfico de ventas por Rover con colores
             progresivos (rojo→verde según % de meta) y línea de meta. El
             degradado de la barra es un valor calculado (barColor), no un
             color estatico, no se toca. -->
        <div v-if="roverRanking && roverRanking.length > 0">
          <p class="text-sm font-semibold text-gray-300 mb-1.5">📊 Ventas por Rover</p>
          <div class="space-y-1 max-w-md">
            <div
              v-for="(entry, index) in visibleRanking"
              :key="entry.rover_id"
              class="flex items-center gap-2 text-xs"
            >
              <span class="w-6 text-center shrink-0">{{ rankBadge(index) }}</span>
              <span class="w-28 text-right text-gray-300 truncate shrink-0">{{ entry.name }}</span>
              <!-- Barra con overflow-hidden para recortar la barra coloreada. El
                   degradado (barColor/barWidth) ya comunica el % de meta, asi
                   que no se dibuja una linea de meta aparte (se probo y
                   generaba ruido visual / sensacion de barra cortada). -->
              <div class="flex-1 min-w-0">
                <div class="bg-surface-3 rounded h-4 overflow-hidden">
                  <div
                    class="h-4 rounded transition-all duration-300"
                    :style="{ width: barWidth(entry.total_portions) + '%', backgroundColor: barColor(entry.total_portions) }"
                  />
                </div>
              </div>
              <span class="shrink-0 text-white font-semibold w-8 text-left">{{ entry.total_portions }}</span>
            </div>
          </div>
          <button
            v-if="roverRanking.length > 10"
            type="button"
            class="text-xs text-ember hover:text-ember-strong mt-1.5"
            @click="showFullRanking = !showFullRanking"
          >
            {{ showFullRanking ? 'Ver menos' : `Ver ranking completo (${roverRanking.length})` }}
          </button>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
          <div class="relative flex-1 min-w-[14rem] max-w-xl">
            <input
              ref="searchInput"
              v-model="search"
              type="text"
              placeholder="Buscar cliente (nombre, apellido, telefono, N° histórico)..."
              class="w-full bg-gray-800 text-white border border-gray-600 rounded-md px-3 py-2 text-sm"
              @keydown.enter="applySearch"
              @focus="showSuggestions = suggestions.length > 0"
              @blur="() => setTimeout(() => (showSuggestions = false), 150)"
            />
            <!-- Fase 7 (correccion 3): sugerencias en tiempo real (sin apretar Enter),
                 mismo patron que Clients/Index.vue. -->
            <div
              v-if="showSuggestions && (suggestions.length || suggesting)"
              class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-md shadow-lg max-h-64 overflow-y-auto"
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
                No encontramos ese cliente.
              </div>
            </div>
          </div>
          <button class="shrink-0 bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded-md text-sm" @click="applySearch">
            Buscar
          </button>

          <!-- Fase 18: filtros agrupados detras de este boton (se mantiene la
               busqueda siempre visible arriba). El panel no elimina ningun
               filtro existente, solo lo reorganiza. -->
          <button
            type="button"
            class="shrink-0 px-3 py-2 rounded-md text-sm border transition-colors flex items-center gap-1.5"
            :class="showFilters ? 'bg-gray-700 text-white border-gray-500' : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
            @click="showFilters = !showFilters"
          >
            <span>🔎 Filtros</span>
            <span v-if="activeFilterCount > 0" class="bg-gray-600 text-white text-[10px] leading-none rounded-full px-1.5 py-1">{{ activeFilterCount }}</span>
            <span class="text-[10px]">{{ showFilters ? '▲' : '▼' }}</span>
          </button>
        </div>

        <div v-if="showFilters" class="flex flex-wrap gap-2 items-center">
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
            <option value="">Estado: todos</option>
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
              ? 'bg-gray-700 text-white border-gray-500'
              : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
            @click="reloadWith({ my_assigned_clients: filters.my_assigned_clients ? undefined : '1' })"
          >
            Mis clientes asignados
          </button>

          <!-- Fase 8 (correccion): "Mis ventas" reemplaza "Mis pedidos cargados".
               Filtra por rover_id = auth user (mismo criterio que el indicador
               "Vendidas por mí" y roverRanking), no por created_by. -->
          <button
            class="px-3 py-2 rounded-md text-sm border transition-colors"
            :class="filters.my_sales
              ? 'bg-gray-700 text-white border-gray-500'
              : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
            @click="reloadWith({ my_sales: filters.my_sales ? undefined : '1' })"
          >
            Mis ventas
          </button>
        </div>
      </div>

      <!-- Acciones masivas -->
      <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-2 mb-3 bg-gray-800 text-white rounded-md px-3 py-2 text-sm">
        <span>{{ selected.size }} seleccionado(s)</span>

        <!-- Fase 7, seccion 10: accion principal destacada. -->
        <button
          v-if="canRegisterPayment && canWithdraw"
          class="bg-green-600 hover:bg-green-500 px-3 py-1.5 rounded-md font-semibold"
          @click="rememberTrigger($event); showPayAndWithdrawModal = true"
        >
          Cobrar y retirar seleccionados
        </button>

        <button v-if="canAssignRover" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded-md" @click="rememberTrigger($event); showAssignModal = true">
          Asignar Rover
        </button>
        <button v-if="canRegisterPayment" class="bg-green-600 hover:bg-green-500 px-3 py-1 rounded-md" @click="rememberTrigger($event); showPayModal = true">
          Registrar pago
        </button>
        <!-- Accion secundaria: se mantiene para casos especiales (ver seccion 10). -->
        <button v-if="canWithdraw" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded-md" @click="rememberTrigger($event); showWithdrawModal = true">
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
              <th class="p-2 text-left">A cobrar</th>
              <th class="p-2 text-left">Pendiente</th>
              <th class="p-2 text-left">Estado</th>
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
              <td class="p-2">
                <!-- Fase 18.1: sobrepago (saldo negativo) deja de mostrarse
                     como un numero negativo silencioso, pasa a un estado
                     explicito en rojo. -->
                <span v-if="Number(order.balance_due) < 0" class="text-red-400 font-semibold">
                  Sobrepago: devolver {{ money(Math.abs(order.balance_due)) }}
                </span>
                <span v-else>{{ money(order.balance_due) }}</span>
              </td>
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
                <!-- Fase 18.1: un Rover comun (sin 'pedidos.asignar-rover')
                     solo puede editar SUS propios pedidos (ver
                     OrderPolicy::update); se oculta el link para los ajenos
                     en vez de dejar que llegue a un 403. -->
                <Link
                  v-if="canAssignRover || order.rover_id === page.props.auth.user.id"
                  :href="`/orders/${order.id}/edit`"
                  class="text-blue-400 hover:text-blue-300"
                >
                  Editar
                </Link>
                <span v-else class="text-gray-600 text-xs">—</span>
              </td>
            </tr>
            <tr v-if="!orders.data.length">
              <td colspan="12">
                <EmptyState
                  title="No encontramos pedidos"
                  :description="emptyOrdersDescription"
                >
                  <template #icon>
                    <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9 9 0 0 0 9-9H3a9 9 0 0 0 9 9Z" /><path d="M7 21h10" /><path d="M19.5 12 22 6" /><path d="M16.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.73 1.62" /><path d="M11.25 3c.27.1.8.53.74 1.36-.05.83-.93 1.2-.98 2.02-.06.78.33 1.24.72 1.62" /><path d="M6.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.74 1.62" /></svg>
                  </template>
                  <template v-if="can('pedidos.crear') && !isFiltering" #action>
                    <Link href="/orders/create" class="text-blue-400 hover:text-blue-300 text-sm">
                      Crear el primer pedido
                    </Link>
                  </template>
                </EmptyState>
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
            link.active ? 'bg-gray-700 text-white' : 'bg-gray-800 text-gray-300',
            !link.url ? 'opacity-40 pointer-events-none' : 'hover:bg-gray-700',
          ]"
        />
      </div>
    </div>

    <AssignOrderModal
      :show="showAssignModal"
      :orders="selectedOrders"
      :rovers="rovers"
      @close="showAssignModal = false; returnFocus()"
      @done="onBulkActionDone"
    />
    <PayOrderModal
      :show="showPayModal"
      :orders="selectedOrders"
      :payment-methods="paymentMethods"
      @close="showPayModal = false; returnFocus()"
      @done="onBulkActionDone"
    />
    <WithdrawOrderModal
      :show="showWithdrawModal"
      :orders="selectedOrders"
      @close="showWithdrawModal = false; returnFocus()"
      @done="onBulkActionDone"
    />

    <!-- Fase 7, seccion 10: confirmacion detallada de "Cobrar y retirar".
         Fase 18: migrado a <Modal> (antes un <div> a mano) para que cierre
         con Escape como el resto de los modales de la app. -->
    <Modal :show="showPayAndWithdrawModal" max-width="lg" @close="showPayAndWithdrawModal = false; returnFocus()">
      <div class="bg-gray-900 text-white rounded-lg p-5 space-y-4">
        <h3 class="font-semibold text-lg">Cobrar y retirar seleccionados</h3>

        <div class="max-h-64 overflow-y-auto divide-y divide-gray-800 text-sm">
          <div v-for="o in payAndWithdrawSummary.items" :key="o.id" class="py-2 flex justify-between">
            <div>
              <p class="font-medium">{{ o.client?.first_name }} {{ o.client?.last_name }}</p>
              <p class="text-xs text-gray-400">{{ o.total_portions }} porciones</p>
            </div>
            <p class="text-sm" :class="Number(o.balance_due) > 0 ? 'text-yellow-400' : 'text-green-400'">
              {{ Number(o.balance_due) > 0 ? `Pendiente: ${money(o.balance_due)}` : 'Sin pendiente' }}
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
          <button type="button" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-md text-sm" @click="showPayAndWithdrawModal = false; returnFocus()">
            Cancelar
          </button>
          <button
            type="button"
            :disabled="payAndWithdrawBusy || !payAndWithdrawMethod"
            class="bg-green-600 hover:bg-green-500 disabled:opacity-50 px-3 py-1.5 rounded-md text-sm font-semibold"
            @click="confirmPayAndWithdraw"
          >
            {{ payAndWithdrawBusy ? 'Procesando...' : 'Confirmar' }}
          </button>
        </div>
      </div>
    </Modal>

    <ToastContainer />
  </AppLayout>
</template>
