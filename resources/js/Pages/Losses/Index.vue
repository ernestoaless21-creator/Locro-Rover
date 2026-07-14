<script setup>
/**
 * Fase 5B: listado simple de porciones perdidas de la edicion seleccionada.
 * Mismo patron que Gifts/Index.vue. Gateado enteramente por
 * 'perdidas.gestionar' en el backend (ver LossController).
 */
import { Head, router, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import LossFormModal from '@/Components/LossFormModal.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  losses: { type: Array, required: true },
  year: { type: Object, required: true },
  years: { type: Array, required: true },
  totalPortions: { type: Number, required: true },
})

const page = usePage()
const can = (perm) => (page.props.permissions ?? []).includes(perm)
const toast = useToast()

const showFormModal = ref(false)
const editingLoss = ref(null)

function openCreate() {
  editingLoss.value = null
  showFormModal.value = true
}

function openEdit(loss) {
  editingLoss.value = loss
  showFormModal.value = true
}

function onSaved() {
  router.reload({ only: ['losses', 'totalPortions'] })
}

function destroyOne(loss) {
  if (!confirm(`¿Eliminar la pérdida de ${loss.quantity} porción(es)?`)) return
  router.delete(`/losses/${loss.id}`, {
    preserveScroll: true,
    onSuccess: () => toast.success('Pérdida eliminada.'),
  })
}

function formatDate(value) {
  return new Date(value).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<template>
  <Head title="Pérdidas" />

  <AppLayout title="Pérdidas">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Pérdidas — {{ year.label || `Locro ${year.year}` }}
        </h2>
        <div class="flex items-center gap-3">
          <YearSelector :selected-year-id="year.id" />
          <button
            v-if="can('perdidas.gestionar')"
            type="button"
            class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap"
            @click="openCreate"
          >
            + Nueva pérdida
          </button>
        </div>
      </div>
    </template>

    <div class="py-6 max-w-4xl mx-auto px-4 space-y-4">
      <div class="bg-gray-900 text-white rounded-lg p-4 flex justify-between items-center">
        <span class="text-sm text-gray-400">Total de porciones perdidas en esta edición</span>
        <span class="text-2xl font-semibold">{{ totalPortions }}</span>
      </div>

      <p class="text-xs text-gray-500">
        Las pérdidas no son ventas, no generan importe ni pagos. Solo descuentan stock disponible
        para la venta (ver Dashboard).
      </p>

      <div class="overflow-x-auto rounded-md border border-gray-700">
        <table class="w-full text-sm bg-gray-900 text-white">
          <thead class="bg-gray-800">
            <tr>
              <th class="p-2 text-left">Porciones</th>
              <th class="p-2 text-left">Motivo</th>
              <th class="p-2 text-left">Registrado por</th>
              <th class="p-2 text-left">Fecha</th>
              <th class="p-2 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="loss in losses" :key="loss.id" class="border-t border-gray-800 hover:bg-gray-800/60">
              <td class="p-2">{{ loss.quantity }}</td>
              <td class="p-2 text-gray-400">{{ loss.reason || '—' }}</td>
              <td class="p-2 text-gray-400">{{ loss.created_by?.name ?? '—' }}</td>
              <td class="p-2 text-gray-400">{{ formatDate(loss.created_at) }}</td>
              <td class="p-2 flex gap-3">
                <button
                  v-if="can('perdidas.gestionar')"
                  type="button"
                  class="text-gray-300 hover:text-white"
                  @click="openEdit(loss)"
                >
                  Editar
                </button>
                <button
                  v-if="can('perdidas.gestionar')"
                  type="button"
                  class="text-red-400 hover:text-red-300"
                  @click="destroyOne(loss)"
                >
                  Eliminar
                </button>
              </td>
            </tr>
            <tr v-if="!losses.length">
              <td colspan="5" class="p-6 text-center text-gray-500">
                Sin pérdidas registradas en esta edición.
                <button v-if="can('perdidas.gestionar')" type="button" class="text-blue-400 block mt-1 mx-auto" @click="openCreate">
                  Registrar la primera
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <LossFormModal
      :show="showFormModal"
      :loss="editingLoss"
      :year-id="year.id"
      @close="showFormModal = false"
      @saved="onSaved"
    />
    <ToastContainer />
  </AppLayout>
</template>
