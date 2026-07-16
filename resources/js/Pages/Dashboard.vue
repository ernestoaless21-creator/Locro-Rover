<script setup>
/**
 * FASE 5A/18: Dashboard operativo real. Todos los datos ya vienen scopeados y
 * gateados desde el backend (DashboardController) segun el rol/permisos del
 * usuario -- este componente solo muestra lo que recibe, no decide que
 * ocultar (las claves financieras/ranking directamente no existen en el
 * payload si el usuario no tiene permiso, ver DashboardController::index).
 *
 * Fase 18: rediseño de distribucion (propuesta aprobada). Mismos datos,
 * misma autorizacion, sin metricas ni funcionalidades nuevas. Sigue siendo
 * pantalla secundaria de consulta (no se agrega acceso fijo en la
 * navegacion; Pedidos sigue siendo la pantalla principal, ver Fase 7).
 * Orden: Acciones rapidas -> Meta de venta -> Estado general -> Atencion ->
 * Produccion -> Finanzas -> Ranking.
 */
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'

const props = defineProps({
  year: { type: Object, required: true },
  years: { type: Array, required: true },
  canViewAll: { type: Boolean, default: false },
  canViewFinancials: { type: Boolean, default: false },
  canViewProduction: { type: Boolean, default: false },
  canCreateOrder: { type: Boolean, default: false },
  canManageParameters: { type: Boolean, default: false },
  canManageGifts: { type: Boolean, default: false },
  canManageLosses: { type: Boolean, default: false },
  metrics: { type: Object, required: true },
  goals: { type: Object, required: true },
  withdrawal: { type: Object, required: true },
  payments: { type: Object, required: true },
  attention: { type: Object, required: true },
  financials: { type: Object, default: null },
  production: { type: Object, default: null },
  ranking: { type: Array, default: null },
})

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}

function ordersUrl(params) {
  const query = new URLSearchParams({ year_id: props.year.id, ...params }).toString()
  return `/orders?${query}`
}

// Mismo degrade rojo->verde segun % de meta que ya usa el ranking por Rover
// en Orders/Index.vue (barColor) -- se reutiliza el mismo lenguaje visual
// en vez de un azul decorativo nuevo.
function goalBarColor(sold, goal) {
  if (!goal) return 'hsl(220, 65%, 55%)'
  const pct = Math.min(sold / goal, 1)
  return `hsl(${Math.round(pct * 120)}, 65%, 45%)`
}
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout title="Dashboard">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-white leading-tight">
          Dashboard — {{ year.label || `Locro ${year.year}` }}
        </h2>
        <YearSelector :selected-year-id="year.id" />
      </div>
    </template>

    <div class="py-6 max-w-7xl mx-auto px-4 space-y-6">
      <!-- Acciones rapidas: solo lo que NO esta ya a un clic en la navegacion
           (Pedidos/Clientes son ítems fijos de primer nivel; Parámetros vive
           en "Organización" — no se repiten aca, Fase 18). -->
      <div class="flex flex-wrap gap-2">
        <Link v-if="canCreateOrder" href="/orders/create" class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold">
          + Nuevo pedido
        </Link>
        <Link href="/assignments" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Asignaciones / Call center
        </Link>
        <Link v-if="canManageGifts" href="/gifts" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Regalos
        </Link>
        <Link v-if="canManageLosses" href="/losses" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Pérdidas
        </Link>
      </div>

      <!-- Meta de venta: lo primero despues de las acciones -- es la
           respuesta mas directa a "como va el Locro" (Fase 18). -->
      <div>
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><circle cx="12" cy="12" r="6" /><circle cx="12" cy="12" r="2" /></svg>
          Meta de venta
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-1">Progreso hacia la meta global</p>
            <template v-if="goals.global_goal">
              <p class="text-lg font-semibold mb-1">{{ goals.global_sold }} / {{ goals.global_goal }} porciones ({{ goals.global_progress_pct }}%)</p>
              <div class="bg-gray-800 rounded h-3 overflow-hidden">
                <div class="bg-green-600 h-3" :style="{ width: `${Math.min(100, goals.global_progress_pct)}%` }"></div>
              </div>
            </template>
            <p v-else class="text-sm text-gray-500">No hay meta global configurada todavía.</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-1">Mi progreso individual</p>
            <template v-if="goals.individual_goal">
              <p class="text-lg font-semibold mb-1">{{ goals.individual_sold }} / {{ goals.individual_goal }} porciones ({{ goals.individual_progress_pct }}%)</p>
              <div class="bg-gray-800 rounded h-3 overflow-hidden">
                <div
                  class="h-3 transition-none"
                  :style="{ width: `${Math.min(100, goals.individual_progress_pct)}%`, backgroundColor: goalBarColor(goals.individual_sold, goals.individual_goal) }"
                ></div>
              </div>
            </template>
            <p v-else class="text-sm text-gray-500">No hay meta individual configurada todavía.</p>
          </div>
        </div>
      </div>

      <!-- Estado general: fusiona "Metricas principales" + "Estado de
           retiro" + conteos de "Pagos" en una sola tarjeta de 4 columnas
           (Fase 18). Mismos datos, menos tarjetas apiladas. -->
      <div>
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1" /><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" /><path d="M12 11h4" /><path d="M12 16h4" /><path d="M8 11h.01" /><path d="M8 16h.01" /></svg>
          Estado general
        </h3>
        <div class="bg-gray-900 text-white rounded-lg p-4 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-4">
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Pedidos</p>
            <Link :href="ordersUrl({ status: 'confirmado' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>Confirmados</span><span class="font-semibold">{{ metrics.confirmados }}</span>
            </Link>
            <Link :href="ordersUrl({ status: 'pendiente' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>Pendientes</span><span class="font-semibold">{{ metrics.pendientes }}</span>
            </Link>
            <Link :href="ordersUrl({ status: 'cancelado' })" class="flex justify-between py-0.5 hover:text-gray-300 text-gray-500">
              <span>Cancelados</span><span class="font-semibold">{{ metrics.cancelados }}</span>
            </Link>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Porciones</p>
            <div class="flex justify-between py-0.5 text-gray-300">
              <span>Vendidas (global)</span><span class="font-semibold">{{ metrics.portions_sold }}</span>
            </div>
            <div class="flex justify-between py-0.5 text-gray-300">
              <span>Vendidas por mí</span><span class="font-semibold">{{ metrics.personal_portions_sold }}</span>
            </div>
            <div class="flex justify-between py-0.5 text-gray-300">
              <span>Salsas incluidas</span><span class="font-semibold">{{ metrics.sauces_total }}</span>
            </div>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Retiro</p>
            <Link :href="ordersUrl({ withdrawal_status: 'no_retirado' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>No retirado</span>
              <span class="text-right"><span class="font-semibold">{{ withdrawal.no_retirado.count }}</span> <span class="text-gray-500 text-[11px]">({{ withdrawal.no_retirado.portions }} porc.)</span></span>
            </Link>
            <Link :href="ordersUrl({ withdrawal_status: 'parcial' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>Parcial</span>
              <span class="text-right"><span class="font-semibold">{{ withdrawal.parcial.count }}</span> <span class="text-gray-500 text-[11px]">({{ withdrawal.parcial.portions }} porc.)</span></span>
            </Link>
            <Link :href="ordersUrl({ withdrawal_status: 'retirado' })" class="flex justify-between py-0.5 hover:text-green-300 text-green-500">
              <span>Retirado</span>
              <span class="text-right"><span class="font-semibold">{{ withdrawal.retirado.count }}</span> <span class="text-green-600 text-[11px]">({{ withdrawal.retirado.portions }} porc.)</span></span>
            </Link>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Pago</p>
            <Link :href="ordersUrl({ payment_status: 'pendiente' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>Sin pagos</span><span class="font-semibold">{{ payments.unpaid_count }}</span>
            </Link>
            <Link :href="ordersUrl({ payment_status: 'parcial' })" class="flex justify-between py-0.5 hover:text-white text-gray-300">
              <span>Parcial</span><span class="font-semibold">{{ payments.partial_count }}</span>
            </Link>
            <Link :href="ordersUrl({ payment_status: 'pagado' })" class="flex justify-between py-0.5 hover:text-green-300 text-green-500">
              <span>Pagado</span><span class="font-semibold">{{ payments.paid_count }}</span>
            </Link>
          </div>
        </div>
      </div>

      <!-- Atencion: se mantiene (aprobado con ajuste), compacta y con las
           3 alertas concretas, sin obligar a interpretar otras tarjetas. -->
      <div>
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" /><path d="M12 9v4" /><path d="M12 17h.01" /></svg>
          Atención
        </h3>
        <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
          <Link :href="ordersUrl({ withdrawal_status: 'no_retirado' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
            <span class="text-amber-400">Pedidos sin retirar</span>
            <span class="font-semibold text-amber-400">{{ attention.not_withdrawn_count }}</span>
          </Link>
          <Link :href="ordersUrl({ payment_status: 'parcial' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
            <span class="text-amber-400">Pagos pendientes</span>
            <span class="font-semibold text-amber-400">{{ attention.pending_balance_count }}</span>
          </Link>
          <div v-if="attention.unassigned_count > 0" class="flex justify-between px-2 py-1">
            <span class="text-amber-400">Pedidos sin Rover asignado</span>
            <span class="font-semibold text-amber-400">{{ attention.unassigned_count }}</span>
          </div>
        </div>
      </div>

      <!-- Produccion real (Fase 6A: sensible, solo Logistica/Jefe de
           Logistica/Admin). Fase 18: franja tipo balance en vez de 5 cajas
           iguales, para destacar el numero final (disponibles restantes). -->
      <div v-if="production">
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16h.01" /><path d="M16 16h.01" /><path d="M3 19a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5a.5.5 0 0 0-.769-.422l-4.462 2.844A.5.5 0 0 1 15 10.5v-2a.5.5 0 0 0-.769-.422L9.77 10.922A.5.5 0 0 1 9 10.5V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z" /><path d="M8 16h.01" /></svg>
          Producción (solo Logística)
        </h3>
        <div class="bg-gray-900 text-white rounded-lg p-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
          <span class="text-gray-300">Elaboradas <strong class="text-white">{{ production.made_portions }}</strong></span>
          <span class="text-gray-600">−</span>
          <Link v-if="canManageGifts" :href="'/gifts'" class="text-gray-300 hover:text-white">Regaladas <strong class="text-white">{{ production.gifted_portions }}</strong></Link>
          <span v-else class="text-gray-300">Regaladas <strong class="text-white">{{ production.gifted_portions }}</strong></span>
          <span class="text-gray-600">−</span>
          <Link v-if="canManageLosses" :href="'/losses'" class="text-gray-300 hover:text-white">Perdidas <strong class="text-white">{{ production.lost_portions }}</strong></Link>
          <span v-else class="text-gray-300">Perdidas <strong class="text-white">{{ production.lost_portions }}</strong></span>
          <span class="text-gray-600">=</span>
          <span class="text-gray-300">Aptas para la venta <strong class="text-white">{{ production.eligible_portions }}</strong></span>
          <span class="text-gray-600">−</span>
          <span class="text-gray-300">Vendidas <strong class="text-white">{{ metrics.portions_sold }}</strong></span>
          <span class="text-gray-600">=</span>
          <span class="ml-auto bg-red-950/40 border border-red-900/50 rounded-md px-3 py-1.5">
            <strong class="text-lg" :class="production.portions_remaining < 0 ? 'text-red-400' : 'text-white'">{{ production.portions_remaining }}</strong>
            <span class="text-xs text-gray-400 ml-1">disponibles restantes</span>
          </span>
        </div>
      </div>

      <!-- Finanzas: importes globales + recaudacion por metodo (solo
           finanzas.ver). Barras de recaudacion: gris neutro, ya no azul
           (Fase 18 -- rojo y verde quedan reservados). -->
      <div v-if="financials">
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2" /><circle cx="12" cy="12" r="2" /><path d="M6 12h.01M18 12h.01" /></svg>
          Finanzas
        </h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div class="bg-gray-900 text-white rounded-lg p-4 space-y-1">
            <div class="flex justify-between"><span class="text-gray-400">Importe total vendido</span><strong>{{ money(financials.total_amount) }}</strong></div>
            <div class="flex justify-between"><span class="text-gray-400">Total cobrado</span><strong>{{ money(financials.total_paid) }}</strong></div>
            <div class="flex justify-between"><span class="text-gray-400">Saldo pendiente</span><strong>{{ money(financials.balance_due) }}</strong></div>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
            <div v-for="m in financials.by_method" :key="m.name" class="flex items-center gap-3">
              <span class="w-28 text-sm">{{ m.name }}</span>
              <div class="flex-1 bg-gray-800 rounded h-4 overflow-hidden">
                <div
                  class="bg-gray-400 h-4"
                  :style="{ width: financials.total_paid > 0 ? `${Math.min(100, (m.total / financials.total_paid) * 100)}%` : '0%' }"
                ></div>
              </div>
              <span class="text-sm w-28 text-right">{{ money(m.total) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Ranking por Rover (solo con pedidos.ver-todos). Sin cambios de
           contenido ni columnas -- Fase 18 solo ajusta paleta. -->
      <div v-if="ranking">
        <h3 class="text-sm text-gray-500 mb-2 flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14.66v1.626a2 2 0 0 1-.976 1.696A5 5 0 0 0 7 21.978" /><path d="M14 14.66v1.626a2 2 0 0 0 .976 1.696A5 5 0 0 1 17 21.978" /><path d="M18 9h1.5a1 1 0 0 0 0-5H18" /><path d="M4 22h16" /><path d="M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z" /><path d="M6 9H4.5a1 1 0 0 1 0-5H6" /></svg>
          Ranking de ventas por Rover
        </h3>
        <div class="overflow-x-auto rounded-lg border border-gray-700">
          <table class="w-full text-sm bg-gray-900 text-white">
            <thead class="bg-gray-800">
              <tr>
                <th class="p-2 text-left">Rover</th>
                <th class="p-2 text-left">Pedidos</th>
                <th class="p-2 text-left">Porciones</th>
                <th v-if="financials" class="p-2 text-left">Importe</th>
                <th v-if="financials" class="p-2 text-left">Cobrado</th>
                <th v-if="financials" class="p-2 text-left">Saldo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in ranking" :key="row.rover" class="border-t border-gray-800">
                <td class="p-2">{{ row.rover }}</td>
                <td class="p-2">{{ row.orders_count }}</td>
                <td class="p-2">{{ row.portions }}</td>
                <td v-if="financials" class="p-2">{{ money(row.amount) }}</td>
                <td v-if="financials" class="p-2">{{ money(row.paid) }}</td>
                <td v-if="financials" class="p-2">{{ money(row.balance) }}</td>
              </tr>
              <tr v-if="!ranking.length">
                <td :colspan="financials ? 6 : 3" class="p-4 text-center text-gray-500">Sin pedidos todavía.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
