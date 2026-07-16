<script setup>
/**
 * Fase 6A. Pantalla de asignaciones anuales de clientes / call center.
 * Todos los usuarios operativos activos pueden ver todo, filtrar, buscar,
 * actualizar seguimiento y autoasignarse una asignacion libre. Transferir,
 * acciones masivas, generar desde edicion anterior y numero historico
 * quedan gateados server-side (los botones ya vienen ocultos segun los
 * `can*` recibidos, pero la autorizacion real vive en el backend).
 */
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  assignments: { type: Object, required: true },
  year: { type: Object, required: true },
  years: { type: Array, required: true },
  statuses: { type: Object, required: true },
  users: { type: Array, required: true },
  filters: { type: Object, default: () => ({}) },
  canTransfer: { type: Boolean, default: false },
  canBulk: { type: Boolean, default: false },
  canGenerate: { type: Boolean, default: false },
  canManageHistoricalNumber: { type: Boolean, default: false },
  canViewFinancials: { type: Boolean, default: false },
})

const toast = useToast()

const search = ref(props.filters.search || '')
const assignedUserId = ref(props.filters.assigned_user_id || '')
const contactStatus = ref(props.filters.contact_status || '')
const unassignedOnly = ref(!!props.filters.unassigned_only)

// Fase 18: filtros avanzados (Responsable/Estado/Solo sin asignar) agrupados
// detras de un boton "Filtros". La busqueda principal queda siempre visible.
const showFilters = ref(Boolean(assignedUserId.value || contactStatus.value || unassignedOnly.value))
const activeFilterCount = computed(() => [assignedUserId.value, contactStatus.value, unassignedOnly.value].filter(Boolean).length)

const selected = ref([])
const bulkTargetUser = ref('')
const distributeUsers = ref([])
const showGenerateModal = ref(false)
const generatePreview = ref(null)
const generateFromYearId = ref('')
const generateBusy = ref(false)

function applyFilters() {
  router.get('/assignments', {
    year_id: props.year.id,
    search: search.value || undefined,
    assigned_user_id: assignedUserId.value || undefined,
    contact_status: contactStatus.value || undefined,
    unassigned_only: unassignedOnly.value ? 1 : undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function toggleSelected(id) {
  const idx = selected.value.indexOf(id)
  if (idx === -1) selected.value.push(id)
  else selected.value.splice(idx, 1)
}

const allSelected = computed(() =>
  props.assignments.data.length > 0 && props.assignments.data.every((a) => selected.value.includes(a.id))
)

function toggleSelectAll() {
  if (allSelected.value) {
    selected.value = selected.value.filter((id) => !props.assignments.data.some((a) => a.id === id))
  } else {
    const ids = props.assignments.data.map((a) => a.id)
    selected.value = [...new Set([...selected.value, ...ids])]
  }
}

function updateContact(assignment, patch) {
  router.put(`/assignments/${assignment.id}/contact`, {
    contact_status: patch.contact_status ?? assignment.contact_status,
    notes: patch.notes ?? assignment.notes,
    mark_contacted_now: true,
  }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Seguimiento actualizado.'),
    onError: () => toast.error('No se pudo actualizar el seguimiento.'),
  })
}

function selfAssign(assignment) {
  router.post(`/assignments/${assignment.id}/self-assign`, {}, {
    preserveScroll: true,
    onSuccess: () => toast.success('Cliente autoasignado.'),
    onError: () => toast.error('No se pudo autoasignar.'),
  })
}

function transfer(assignment, userId) {
  if (!userId) return
  router.post(`/assignments/${assignment.id}/transfer`, { assigned_user_id: userId }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Asignación transferida.'),
    onError: () => toast.error('No se pudo transferir.'),
  })
}

function bulkAssign() {
  if (!bulkTargetUser.value || !selected.value.length) return
  router.post('/assignments/bulk-assign', {
    assignment_ids: selected.value,
    assigned_user_id: bulkTargetUser.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      toast.success('Asignación masiva realizada.')
      selected.value = []
      router.reload({ only: ['assignments'] })
    },
    onError: () => toast.error('No se pudo asignar en masa.'),
  })
}

function bulkDistribute() {
  if (distributeUsers.value.length < 1 || !selected.value.length) return
  router.post('/assignments/bulk-distribute', {
    assignment_ids: selected.value,
    user_ids: distributeUsers.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      toast.success('Reparto equitativo realizado.')
      selected.value = []
      router.reload({ only: ['assignments'] })
    },
    onError: () => toast.error('No se pudo repartir.'),
  })
}

function openGenerateModal() {
  generatePreview.value = null
  generateFromYearId.value = props.years.find((y) => y.id !== props.year.id)?.id || ''
  showGenerateModal.value = true
}

async function previewGenerate() {
  if (!generateFromYearId.value) return
  generateBusy.value = true
  try {
    const res = await fetch('/assignments/generate-preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
      body: JSON.stringify({ from_year_id: generateFromYearId.value, to_year_id: props.year.id }),
    })
    generatePreview.value = await res.json()
  } catch {
    toast.error('No se pudo previsualizar.')
  } finally {
    generateBusy.value = false
  }
}

async function confirmGenerate() {
  generateBusy.value = true
  try {
    const res = await fetch('/assignments/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
      body: JSON.stringify({ from_year_id: generateFromYearId.value, to_year_id: props.year.id }),
    })
    const summary = await res.json()
    toast.success(`Generación completada: ${summary.kept_responsible} conservaron responsable, ${summary.unassigned_inactive} quedaron sin asignar, ${summary.already_existed} ya existían.`)
    showGenerateModal.value = false
    router.reload({ only: ['assignments'] })
  } catch {
    toast.error('No se pudo generar.')
  } finally {
    generateBusy.value = false
  }
}

function exportUrl() {
  const params = new URLSearchParams({
    year_id: props.year.id,
    ...(search.value ? { search: search.value } : {}),
    ...(assignedUserId.value ? { assigned_user_id: assignedUserId.value } : {}),
    ...(contactStatus.value ? { contact_status: contactStatus.value } : {}),
    ...(unassignedOnly.value ? { unassigned_only: 1 } : {}),
  })
  return `/assignments/export?${params.toString()}`
}
</script>

<template>
  <Head title="Asignaciones" />

  <AppLayout title="Asignaciones">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-white leading-tight">Asignaciones / Call center</h2>
        <YearSelector :selected-year-id="year.id" />
      </div>
    </template>

    <div class="py-6 max-w-7xl mx-auto px-4 space-y-4">
      <!-- Busqueda principal (siempre visible) + filtros avanzados colapsables -->
      <div class="bg-gray-900 text-white rounded-lg p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label class="text-xs text-gray-400 block mb-1">Buscar (nombre, teléfono, N° histórico)</label>
          <input v-model="search" @keyup.enter="applyFilters" type="text" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm" />
        </div>
        <button type="button" @click="applyFilters" class="bg-blue-600 hover:bg-blue-500 px-3 py-1.5 rounded-md text-sm">Filtrar</button>

        <button
          type="button"
          class="px-3 py-1.5 rounded-md text-sm border transition-colors flex items-center gap-1.5"
          :class="showFilters ? 'bg-gray-700 border-gray-500' : 'bg-gray-800 border-gray-600 hover:bg-gray-700'"
          @click="showFilters = !showFilters"
        >
          <span>🔎 Filtros</span>
          <span v-if="activeFilterCount > 0" class="bg-blue-600 text-white text-[10px] leading-none rounded-full px-1.5 py-1">{{ activeFilterCount }}</span>
          <span class="text-[10px]">{{ showFilters ? '▲' : '▼' }}</span>
        </button>

        <a :href="exportUrl()" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-md text-sm ml-auto">Exportar Excel</a>
        <button v-if="canGenerate" type="button" @click="openGenerateModal" class="bg-purple-700 hover:bg-purple-600 px-3 py-1.5 rounded-md text-sm">
          Generar desde edición anterior
        </button>
      </div>

      <div v-if="showFilters" class="bg-gray-900 text-white rounded-lg p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label class="text-xs text-gray-400 block mb-1">Responsable</label>
          <select v-model="assignedUserId" @change="applyFilters" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm">
            <option value="">Todos</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-gray-400 block mb-1">Estado</label>
          <select v-model="contactStatus" @change="applyFilters" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm">
            <option value="">Todos</option>
            <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" v-model="unassignedOnly" @change="applyFilters" />
          Solo sin asignar
        </label>
      </div>

      <!-- Acciones masivas -->
      <div v-if="canBulk && selected.length" class="bg-gray-900 text-white rounded-lg p-4 flex flex-wrap items-end gap-3">
        <span class="text-sm text-gray-400">{{ selected.length }} seleccionados</span>
        <div>
          <label class="text-xs text-gray-400 block mb-1">Asignar todos a</label>
          <select v-model="bulkTargetUser" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm">
            <option value="">Elegir usuario...</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <button type="button" @click="bulkAssign" class="bg-blue-600 hover:bg-blue-500 px-3 py-1.5 rounded-md text-sm">Asignar</button>

        <div>
          <label class="text-xs text-gray-400 block mb-1">Repartir equitativamente entre</label>
          <select v-model="distributeUsers" multiple class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm min-w-[10rem]">
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <button type="button" @click="bulkDistribute" class="bg-blue-600 hover:bg-blue-500 px-3 py-1.5 rounded-md text-sm">Repartir</button>
      </div>

      <!-- Tabla -->
      <div class="overflow-x-auto rounded-lg border border-gray-700">
        <table class="w-full text-sm bg-gray-900 text-white">
          <thead class="bg-gray-800">
            <tr>
              <th v-if="canBulk" class="p-2"><input type="checkbox" :checked="allSelected" @change="toggleSelectAll" /></th>
              <th class="p-2 text-left">N°</th>
              <th class="p-2 text-left">Cliente</th>
              <th class="p-2 text-left">Teléfono</th>
              <th class="p-2 text-left">Responsable</th>
              <th class="p-2 text-left">Estado</th>
              <th class="p-2 text-left">Último contacto</th>
              <th class="p-2 text-left">Observaciones</th>
              <th class="p-2 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in assignments.data" :key="a.id" class="border-t border-gray-800">
              <td v-if="canBulk" class="p-2"><input type="checkbox" :checked="selected.includes(a.id)" @change="toggleSelected(a.id)" /></td>
              <td class="p-2">{{ a.client.historical_number ?? '—' }}</td>
              <td class="p-2">
                <a :href="`/clients/${a.client.id}/history?year_id=${year.id}`" class="hover:underline">
                  {{ a.client.first_name }} {{ a.client.last_name }}
                </a>
              </td>
              <td class="p-2">{{ a.client.phone }}</td>
              <td class="p-2">
                <span v-if="a.assigned_user" class="mr-1">{{ a.assigned_user.name }}</span>
                <span v-else class="text-yellow-400 mr-1">Sin asignar</span>
                <button v-if="!a.assigned_user" type="button" class="text-blue-400 hover:underline text-xs" @click="selfAssign(a)">
                  Autoasignarme
                </button>
                <select
                  v-if="canTransfer"
                  class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs ml-1"
                  :value="''"
                  @change="transfer(a, $event.target.value); $event.target.value = ''"
                >
                  <option value="" disabled>Transferir a...</option>
                  <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
              </td>
              <td class="p-2">
                <select
                  class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs"
                  :value="a.contact_status"
                  @change="updateContact(a, { contact_status: $event.target.value })"
                >
                  <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
              </td>
              <td class="p-2 text-xs text-gray-400">
                {{ a.last_contacted_at ? new Date(a.last_contacted_at).toLocaleString('es-AR') : '—' }}
                <span v-if="a.last_contacted_by"> ({{ a.last_contacted_by.name }})</span>
              </td>
              <td class="p-2">
                <input
                  type="text"
                  class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs w-40"
                  :value="a.notes"
                  @change="updateContact(a, { notes: $event.target.value })"
                  placeholder="Observación..."
                />
              </td>
              <td class="p-2">
                <a :href="`/orders/create?client_id=${a.client.id}&year_id=${year.id}`" class="text-blue-400 hover:underline text-xs">
                  Crear pedido
                </a>
              </td>
            </tr>
            <tr v-if="!assignments.data.length">
              <td :colspan="canBulk ? 9 : 8" class="p-8 text-center text-gray-500">
                <p class="text-2xl mb-1">🔎</p>
                <p>No hay asignaciones para esta edición con estos filtros.</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginacion simple -->
      <div v-if="assignments.links" class="flex flex-wrap gap-1">
        <template v-for="link in assignments.links" :key="link.label">
          <button
            v-if="link.url"
            type="button"
            class="px-2 py-1 rounded text-xs"
            :class="link.active ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300'"
            @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
            v-html="link.label"
          />
        </template>
      </div>
    </div>

    <!-- Modal: generar desde edicion anterior -->
    <div v-if="showGenerateModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div class="bg-gray-900 text-white rounded-lg p-6 max-w-md w-full space-y-4">
        <h3 class="font-semibold text-lg">Generar asignaciones desde edición anterior</h3>
        <div>
          <label class="text-xs text-gray-400 block mb-1">Edición origen</label>
          <select v-model="generateFromYearId" class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm w-full">
            <option v-for="y in years.filter((y) => y.id !== year.id)" :key="y.id" :value="y.id">{{ y.label || y.year }}</option>
          </select>
        </div>
        <p class="text-sm text-gray-400">Edición destino: <strong>{{ year.label || year.year }}</strong></p>

        <button type="button" :disabled="generateBusy" @click="previewGenerate" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-md text-sm">
          Previsualizar
        </button>

        <div v-if="generatePreview" class="bg-gray-800 rounded-md p-3 text-sm space-y-1">
          <p>Total en origen: {{ generatePreview.total_origin }}</p>
          <p>Conservarán su responsable: {{ generatePreview.kept_responsible }}</p>
          <p>Quedarán sin asignar (responsable inactivo): {{ generatePreview.unassigned_inactive }}</p>
          <p>Ya existen en destino (se omiten): {{ generatePreview.already_existed }}</p>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showGenerateModal = false" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-md text-sm">Cancelar</button>
          <button type="button" :disabled="generateBusy || !generatePreview" @click="confirmGenerate" class="bg-green-600 hover:bg-green-500 px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
            Confirmar y generar
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
