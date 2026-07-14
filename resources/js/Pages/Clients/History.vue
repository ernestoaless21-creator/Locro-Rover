<script setup>
/**
 * Historial de un cliente: pedidos, porciones, pagos y observaciones,
 * filtrable por anio especifico o "todos" (historial completo).
 * El backend (ClientController::history) ya filtra por year_id si viene
 * en la URL; ac lo unico que hacemos es armar los links del selector.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ObservationEditor from '@/Components/ObservationEditor.vue'
import { ref } from 'vue'

const props = defineProps({
  client: { type: Object, required: true },
  years: { type: Array, required: true },
  selectedYearId: { type: Number, default: null },
})

const observations = ref(props.client.observations ?? [])

function selectYear(yearId) {
  router.get(`/clients/${props.client.id}/history`, yearId ? { year_id: yearId } : {}, {
    preserveScroll: true,
  })
}

function onObservationSaved(newObservation) {
  observations.value = [newObservation, ...observations.value]
}

function money(value) {
  if (value === null || value === undefined) return '-'
  return `$${Number(value).toLocaleString('es-AR')}`
}
</script>

<template>
  <Head :title="`Historial de ${client.first_name}`" />

  <AppLayout :title="`Historial de ${client.first_name}`">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ client.first_name }} {{ client.last_name }}
      </h2>
    </template>

    <div class="py-6 max-w-4xl mx-auto px-4 pb-12 bg-gray-900 text-white rounded-lg">
    <div class="p-6">
    <Link href="/clients" class="text-blue-400 text-sm">&larr; Volver a clientes</Link>

    <p class="text-gray-400 text-sm mt-2">{{ client.phone }} — {{ client.address }}</p>

    <div class="flex items-center gap-2 flex-wrap mt-4 mb-6">
      <button
        type="button"
        class="px-3 py-1 rounded-md text-sm"
        :class="!selectedYearId ? 'bg-blue-600' : 'bg-gray-800 hover:bg-gray-700'"
        @click="selectYear(null)"
      >
        Historial completo
      </button>
      <button
        v-for="y in years"
        :key="y.id"
        type="button"
        class="px-3 py-1 rounded-md text-sm"
        :class="selectedYearId === y.id ? 'bg-blue-600' : 'bg-gray-800 hover:bg-gray-700'"
        @click="selectYear(y.id)"
      >
        {{ y.year }}
      </button>
    </div>

    <div class="space-y-6">
      <div
        v-for="order in client.orders"
        :key="order.id"
        class="border border-gray-700 rounded-md p-4"
      >
        <div class="flex flex-wrap justify-between gap-2 text-sm mb-2">
          <span class="font-medium">Edicion {{ order.year?.year }}</span>
          <span class="text-gray-400">Rover: {{ order.rover?.name ?? '-' }}</span>
          <span
            class="px-2 py-0.5 rounded-full text-xs"
            :class="order.withdrawal_status === 'retirado' ? 'bg-green-700' : 'bg-yellow-700'"
          >
            {{ order.withdrawal_status }}
          </span>
          <Link :href="`/orders/${order.id}/edit`" class="text-blue-400 hover:text-blue-300 text-xs">
            Editar pedido
          </Link>
        </div>

        <table class="w-full text-sm mb-2">
          <thead class="text-gray-400 text-left">
            <tr>
              <th class="pr-4">Producto</th>
              <th class="pr-4">Tipo</th>
              <th class="pr-4">Cantidad</th>
              <th class="pr-4">Importe</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in order.items" :key="item.id">
              <td class="pr-4">{{ item.product }}</td>
              <td class="pr-4">{{ item.type }}</td>
              <td class="pr-4">{{ item.quantity }}</td>
              <td class="pr-4">{{ money(item.line_total) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="flex flex-wrap gap-4 text-sm text-gray-300">
          <span>Total: {{ money(order.total_amount) }}</span>
          <span>Pagado: {{ money(order.total_paid) }}</span>
          <span>Saldo: {{ money(order.balance_due) }}</span>
        </div>

        <div v-if="order.payments?.length" class="mt-2 text-xs text-gray-400">
          Pagos:
          <span v-for="p in order.payments" :key="p.id" class="mr-3">
            {{ money(p.amount) }} ({{ p.method?.name }})
          </span>
        </div>
      </div>

      <div v-if="!client.orders?.length" class="text-gray-500 italic">
        Sin pedidos para el periodo seleccionado.
      </div>
    </div>

    <div class="mt-8">
      <h2 class="text-lg font-medium mb-2">Observaciones</h2>
      <ObservationEditor
        :client-id="client.id"
        :year-id="selectedYearId ?? years[0]?.id"
        :observations="observations"
        @saved="onObservationSaved"
      />
    </div>
    </div>
    </div>
  </AppLayout>
</template>
