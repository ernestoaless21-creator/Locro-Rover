<script setup>
/**
 * Fase 5B: listado simple de porciones regaladas/donadas de la edicion
 * seleccionada. Permite crear, editar y eliminar. Gateado enteramente por
 * 'regalos.gestionar' en el backend (ver GiftController); esta pantalla no
 * decide permisos, solo no se renderiza el link de navegacion si no aplica.
 */
import { Head, router, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import GiftFormModal from '@/Components/GiftFormModal.vue'
import HistoricalEditionBanner from '@/Components/HistoricalEditionBanner.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import { useToast } from '@/Composables/useToast'
import { useEditableYear } from '@/Composables/useEditableYear'

const props = defineProps({
  gifts: { type: Array, required: true },
  year: { type: Object, required: true },
  years: { type: Array, required: true },
  totalPortions: { type: Number, required: true },
})

const page = usePage()
const can = (perm) => (page.props.permissions ?? []).includes(perm)
const toast = useToast()
const canMutateYear = useEditableYear(() => props.year)

const showFormModal = ref(false)
const editingGift = ref(null)

function openCreate() {
  editingGift.value = null
  showFormModal.value = true
}

function openEdit(gift) {
  editingGift.value = gift
  showFormModal.value = true
}

function onSaved() {
  router.reload({ only: ['gifts', 'totalPortions'] })
}

function destroyOne(gift) {
  if (!confirm(`¿Eliminar el regalo de ${gift.quantity} porción(es) a ${gift.recipient_name}?`)) return
  router.delete(`/gifts/${gift.id}`, {
    preserveScroll: true,
    onSuccess: () => toast.success('Regalo eliminado.'),
  })
}

function formatDate(value) {
  return new Date(value).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<template>
  <Head title="Regalos" />

  <AppLayout title="Regalos">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-white leading-tight">
          Regalos — {{ year.label || `Locro ${year.year}` }}
        </h2>
        <div class="flex items-center gap-3">
          <YearSelector :selected-year-id="year.id" />
          <button
            v-if="can('regalos.gestionar') && canMutateYear"
            type="button"
            class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold whitespace-nowrap"
            @click="openCreate"
          >
            + Nuevo regalo
          </button>
        </div>
      </div>
    </template>

    <div class="py-6 max-w-4xl mx-auto px-4 space-y-4">
      <HistoricalEditionBanner :year="year" />

      <div class="bg-gray-900 text-white rounded-lg p-4 flex justify-between items-center">
        <span class="text-sm text-gray-400">Total de porciones regaladas en esta edición</span>
        <span class="text-2xl font-semibold">{{ totalPortions }}</span>
      </div>

      <p class="text-xs text-gray-500">
        Los regalos no generan importe, saldo ni pagos, y no cuentan como venta. Solo descuentan
        stock disponible para la venta (ver Dashboard).
      </p>

      <div class="overflow-x-auto rounded-md border border-gray-700">
        <table class="w-full text-sm bg-gray-900 text-white">
          <thead class="bg-gray-800">
            <tr>
              <th class="p-2 text-left">Destinatario</th>
              <th class="p-2 text-left">Porciones</th>
              <th class="p-2 text-left">Observación</th>
              <th class="p-2 text-left">Registrado por</th>
              <th class="p-2 text-left">Fecha</th>
              <th class="p-2 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="gift in gifts" :key="gift.id" class="border-t border-gray-800 hover:bg-gray-800/60">
              <td class="p-2">{{ gift.recipient_name }}</td>
              <td class="p-2">{{ gift.quantity }}</td>
              <td class="p-2 text-gray-400">{{ gift.notes || '—' }}</td>
              <td class="p-2 text-gray-400">{{ gift.created_by?.name ?? '—' }}</td>
              <td class="p-2 text-gray-400">{{ formatDate(gift.created_at) }}</td>
              <td class="p-2 flex gap-3">
                <button
                  v-if="can('regalos.gestionar') && canMutateYear"
                  type="button"
                  class="text-gray-300 hover:text-white"
                  @click="openEdit(gift)"
                >
                  Editar
                </button>
                <button
                  v-if="can('regalos.gestionar') && canMutateYear"
                  type="button"
                  class="text-red-400 hover:text-red-300"
                  @click="destroyOne(gift)"
                >
                  Eliminar
                </button>
              </td>
            </tr>
            <tr v-if="!gifts.length">
              <td colspan="6" class="p-8 text-center text-gray-500">
                <p class="text-2xl mb-1">🎁</p>
                <p class="text-gray-300 font-medium">Todavía no hay regalos registrados en esta edición.</p>
                <p class="text-xs mt-1">Anotá el primero cuando regales una porción.</p>
                <button v-if="can('regalos.gestionar') && canMutateYear" type="button" class="text-blue-400 block mt-2 mx-auto" @click="openCreate">
                  Registrar el primero
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <GiftFormModal
      :show="showFormModal"
      :gift="editingGift"
      :year-id="year.id"
      @close="showFormModal = false"
      @saved="onSaved"
    />
    <ToastContainer />
  </AppLayout>
</template>
