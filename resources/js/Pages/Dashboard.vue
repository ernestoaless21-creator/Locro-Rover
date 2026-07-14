<script setup>
/**
 * FASE 5A: Dashboard operativo real. Todos los datos ya vienen scopeados y
 * gateados desde el backend (DashboardController) segun el rol/permisos del
 * usuario -- este componente solo muestra lo que recibe, no decide que
 * ocultar (las claves financieras/ranking directamente no existen en el
 * payload si el usuario no tiene permiso, ver DashboardController::index).
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
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout title="Dashboard">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Dashboard — {{ year.label || `Locro ${year.year}` }}
        </h2>
        <YearSelector :selected-year-id="year.id" />
      </div>
    </template>

    <div class="py-6 max-w-7xl mx-auto px-4 space-y-6">
      <!-- Acciones rapidas -->
      <div class="flex flex-wrap gap-2">
        <Link v-if="canCreateOrder" href="/orders/create" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-md text-sm">
          + Nuevo pedido
        </Link>
        <Link href="/orders" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Ver pedidos
        </Link>
        <Link href="/clients" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Ver clientes
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
        <Link v-if="canManageParameters" href="/parameters" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-md text-sm">
          Parámetros
        </Link>
      </div>

      <!-- Metricas principales -->
      <div>
        <h3 class="text-sm text-gray-500 mb-2">Métricas principales</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Total de pedidos</p>
            <p class="text-2xl font-semibold">{{ metrics.total_pedidos }}</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Porciones vendidas (global)</p>
            <p class="text-2xl font-semibold">{{ metrics.portions_sold }}</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Mis porciones vendidas</p>
            <p class="text-2xl font-semibold">{{ metrics.personal_portions_sold }}</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Salsas incluidas</p>
            <p class="text-2xl font-semibold">{{ metrics.sauces_total }}</p>
          </div>
          <Link :href="ordersUrl({ status: 'pendiente' })" class="bg-gray-900 text-white rounded-lg p-4 hover:bg-gray-800">
            <p class="text-xs text-gray-400">Pendientes</p>
            <p class="text-2xl font-semibold">{{ metrics.pendientes }}</p>
          </Link>
          <Link :href="ordersUrl({ status: 'confirmado' })" class="bg-gray-900 text-white rounded-lg p-4 hover:bg-gray-800">
            <p class="text-xs text-gray-400">Confirmados</p>
            <p class="text-2xl font-semibold">{{ metrics.confirmados }}</p>
          </Link>
          <Link :href="ordersUrl({ status: 'cancelado' })" class="bg-gray-900 text-white rounded-lg p-4 hover:bg-gray-800">
            <p class="text-xs text-gray-400">Cancelados</p>
            <p class="text-2xl font-semibold text-gray-400">{{ metrics.cancelados }}</p>
          </Link>
        </div>
      </div>

      <!-- Metas de venta (Fase 6A) -->
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
              <div class="bg-blue-600 h-3" :style="{ width: `${Math.min(100, goals.individual_progress_pct)}%` }"></div>
            </div>
          </template>
          <p v-else class="text-sm text-gray-500">No hay meta individual configurada todavía.</p>
        </div>
      </div>

      <!-- Produccion real (Fase 6A: sensible, solo Logistica/Jefe de Logistica/Admin) -->
      <div v-if="production">
        <h3 class="text-sm text-gray-500 mb-2">Producción (solo Logística)</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Porciones elaboradas</p>
            <p class="text-2xl font-semibold">{{ production.made_portions }}</p>
          </div>
          <Link v-if="canManageGifts" href="/gifts" class="bg-gray-900 text-white rounded-lg p-4 hover:bg-gray-800">
            <p class="text-xs text-gray-400">Porciones regaladas</p>
            <p class="text-2xl font-semibold">{{ production.gifted_portions }}</p>
          </Link>
          <div v-else class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Porciones regaladas</p>
            <p class="text-2xl font-semibold">{{ production.gifted_portions }}</p>
          </div>
          <Link v-if="canManageLosses" href="/losses" class="bg-gray-900 text-white rounded-lg p-4 hover:bg-gray-800">
            <p class="text-xs text-gray-400">Porciones perdidas</p>
            <p class="text-2xl font-semibold">{{ production.lost_portions }}</p>
          </Link>
          <div v-else class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Porciones perdidas</p>
            <p class="text-2xl font-semibold">{{ production.lost_portions }}</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Aptas para la venta</p>
            <p class="text-2xl font-semibold">{{ production.eligible_portions }}</p>
          </div>
          <div class="bg-gray-900 text-white rounded-lg p-4">
            <p class="text-xs text-gray-400">Disponibles restantes</p>
            <p class="text-2xl font-semibold" :class="production.portions_remaining < 0 ? 'text-red-400' : ''">
              {{ production.portions_remaining }}
            </p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Estado de retiro -->
        <div>
          <h3 class="text-sm text-gray-500 mb-2">Estado de retiro</h3>
          <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
            <Link :href="ordersUrl({ withdrawal_status: 'no_retirado' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>No retirado</span>
              <span>{{ withdrawal.no_retirado.count }} pedidos — {{ withdrawal.no_retirado.portions }} porciones</span>
            </Link>
            <Link :href="ordersUrl({ withdrawal_status: 'parcial' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>Parcial</span>
              <span>{{ withdrawal.parcial.count }} pedidos — {{ withdrawal.parcial.portions }} porciones</span>
            </Link>
            <Link :href="ordersUrl({ withdrawal_status: 'retirado' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>Retirado</span>
              <span>{{ withdrawal.retirado.count }} pedidos — {{ withdrawal.retirado.portions }} porciones</span>
            </Link>
          </div>
        </div>

        <!-- Pagos y saldos -->
        <div>
          <h3 class="text-sm text-gray-500 mb-2">Pagos</h3>
          <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
            <Link :href="ordersUrl({ payment_status: 'pagado' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>Totalmente pagados</span>
              <span>{{ payments.paid_count }}</span>
            </Link>
            <Link :href="ordersUrl({ payment_status: 'parcial' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>Pago parcial</span>
              <span>{{ payments.partial_count }}</span>
            </Link>
            <Link :href="ordersUrl({ payment_status: 'pendiente' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
              <span>Sin pagos</span>
              <span>{{ payments.unpaid_count }}</span>
            </Link>

            <template v-if="financials">
              <div class="border-t border-gray-700 pt-2 mt-2 space-y-1">
                <div class="flex justify-between"><span class="text-gray-400">Importe total vendido</span><strong>{{ money(financials.total_amount) }}</strong></div>
                <div class="flex justify-between"><span class="text-gray-400">Total cobrado</span><strong>{{ money(financials.total_paid) }}</strong></div>
                <div class="flex justify-between"><span class="text-gray-400">Saldo pendiente</span><strong>{{ money(financials.balance_due) }}</strong></div>
              </div>
            </template>
            <p v-else class="text-xs text-gray-500 pt-2">
              No tenés permiso para ver importes globales.
            </p>
          </div>
        </div>
      </div>

      <!-- Recaudacion por medio de pago (solo con finanzas.ver) -->
      <div v-if="financials">
        <h3 class="text-sm text-gray-500 mb-2">Recaudación por medio de pago</h3>
        <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
          <div v-for="m in financials.by_method" :key="m.name" class="flex items-center gap-3">
            <span class="w-28 text-sm">{{ m.name }}</span>
            <div class="flex-1 bg-gray-800 rounded h-4 overflow-hidden">
              <div
                class="bg-blue-600 h-4"
                :style="{ width: financials.total_paid > 0 ? `${Math.min(100, (m.total / financials.total_paid) * 100)}%` : '0%' }"
              ></div>
            </div>
            <span class="text-sm w-28 text-right">{{ money(m.total) }}</span>
          </div>
        </div>
      </div>

      <!-- Ranking por Rover (solo con pedidos.ver-todos) -->
      <div v-if="ranking">
        <h3 class="text-sm text-gray-500 mb-2">Ranking de ventas por Rover</h3>
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

      <!-- Pedidos que requieren atencion -->
      <div>
        <h3 class="text-sm text-gray-500 mb-2">Requieren atención</h3>
        <div class="bg-gray-900 text-white rounded-lg p-4 space-y-2">
          <Link :href="ordersUrl({ payment_status: 'parcial' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
            <span>Pedidos con saldo pendiente (parcial o sin pagar)</span>
            <span class="font-semibold">{{ attention.pending_balance_count }}</span>
          </Link>
          <Link :href="ordersUrl({ withdrawal_status: 'no_retirado' })" class="flex justify-between hover:bg-gray-800 rounded px-2 py-1 -mx-2">
            <span>Pedidos no retirados (o parcial)</span>
            <span class="font-semibold">{{ attention.not_withdrawn_count }}</span>
          </Link>
          <div v-if="attention.unassigned_count > 0" class="flex justify-between px-2 py-1 text-yellow-400">
            <span>Pedidos sin Rover asignado</span>
            <span class="font-semibold">{{ attention.unassigned_count }}</span>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
