<script setup>
/**
 * Fase 7, secciones 1 y 2: pantalla UNICA de gestion de Clientes, que ahora
 * fusiona visualmente lo que antes era "Clientes" + "Asignaciones" (esa
 * pestaña desaparece del menu superior, ver AppLayout.vue). El backend de
 * Asignaciones (ClientAssignmentController, ClientAssignmentService, rutas
 * /assignments/*) NO se elimina: se sigue usando para exportar, generar desde
 * edicion anterior y las acciones masivas, ver ClientController::index.
 *
 * Permisos (seccion 2): cualquier usuario con 'clientes.ver' puede ver, buscar,
 * ver historial, corregir nombre/apellido/telefono/notas y actualizar el
 * seguimiento. Transferir responsable, accion masiva, generar desde edicion
 * anterior, numero historico y "quitar de la edicion" quedan reservados a
 * Logistica/Jefe de Logistica/Admin (canTransfer/canBulk/canGenerate/
 * canManageHistoricalNumber recibidos del backend).
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import ClientFormModal from '@/Components/ClientFormModal.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  clients: { type: Array, required: true },
  year: { type: Object, required: true },
  years: { type: Array, default: () => [] },
  statuses: { type: Object, default: () => ({}) },
  users: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  canTransfer: { type: Boolean, default: false },
  canBulk: { type: Boolean, default: false },
  canGenerate: { type: Boolean, default: false },
  canManageHistoricalNumber: { type: Boolean, default: false },
  canViewFinancials: { type: Boolean, default: false },
})

const page = usePage()
const can = (perm) => (page.props.permissions ?? []).includes(perm)
const toast = useToast()

// Fase 18: estado vacio contextual (distingue "sin resultados de busqueda/filtro"
// de "todavia no hay clientes cargados").
const isFiltering = computed(() => Boolean(props.filters.search || props.filters.client_id || props.filters.my_assigned_clients))

const search = ref(props.filters.search ?? '')
const suggestions = ref([])
const showSuggestions = ref(false)
const suggesting = ref(false)
let suggestDebounce = null
const selected = ref(new Set())
const deleting = ref(false)
const showFormModal = ref(false)
const editingClient = ref(null)

const bulkTargetUser = ref('')
const distributeUsers = ref([])
const showGenerateModal = ref(false)
const generatePreview = ref(null)
const generateFromYearId = ref('')
const generateBusy = ref(false)

function reloadWith(extra) {
  router.get('/clients', { ...props.filters, ...extra, year_id: props.year.id }, {
    preserveState: true,
    replace: true,
  })
}

function applySearch() {
  // Una busqueda de texto (tipeada + Enter/Buscar) siempre reemplaza una
  // seleccion exacta anterior (ver pickSuggestion): son dos modos
  // mutuamente excluyentes, nunca se combinan.
  reloadWith({ search: search.value || undefined, client_id: undefined })
}

// Fase 7 (correccion 2): al vaciar el buscador, la lista se restaura sola.
let debounceHandle = null
watch(search, (value) => {
  if (value !== '') return
  clearTimeout(debounceHandle)
  debounceHandle = setTimeout(() => applySearch(), 150)
})

// Fase 7 (correccion 2): sugerencias/autocompletado en tiempo real mientras
// se escribe, reutilizando el MISMO endpoint de autocomplete que ya usa
// ClientPicker (/clients/search), que ya soporta nombre, apellido,
// nombre+apellido en cualquier orden, parciales, telefono y N° historico
// (ver Client::scopeSearchTerm). Con debounce para no disparar un request
// por cada tecla.
//
// BUG (Fase P2, UX): pickSuggestion() escribe en `search.value`, y ese mismo
// cambio volvia a disparar ESTE watcher (Vue no distingue una asignacion
// programatica de una tipeada), reabriendo el dropdown de sugerencias
// ~250ms despues del click y tapando visualmente la tabla ya filtrada por
// debajo. Mismo fix que Orders/Index.vue: un flag salta el fetch una sola
// vez cuando el cambio vino de pickSuggestion.
let skipNextSuggestFetch = false
watch(search, (value) => {
  if (skipNextSuggestFetch) {
    skipNextSuggestFetch = false
    return
  }

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
    } finally {
      suggesting.value = false
    }
  }, 250)
})

function pickSuggestion(client) {
  showSuggestions.value = false
  suggestions.value = []
  skipNextSuggestFetch = true
  // BUG (Fase P2, UX): esto antes armaba un termino de TEXTO (nombre o N°
  // historico) y llamaba a applySearch(), que termina en una busqueda LIKE
  // por Client::scopeSearchTerm — esa misma busqueda tambien matchea contra
  // el campo "telefono", asi que un N° historico corto podia traer de rebote
  // clientes cuyo TELEFONO contuviera esa secuencia. Filtrar por click en
  // una sugerencia debe ser exacto: se manda el client_id (ver
  // ClientController::index) en vez de reciclar el buscador de texto. El
  // texto del input queda solo como feedback visual, nunca se envia como
  // `search`.
  search.value = client.historical_number
    ? `#${client.historical_number} — ${client.first_name} ${client.last_name}`
    : `${client.first_name} ${client.last_name}`
  reloadWith({ client_id: client.id, search: undefined })
}

function sortBy(column) {
  const direction = props.filters.sort === column && props.filters.direction === 'asc' ? 'desc' : 'asc'
  reloadWith({ sort: column, direction })
}

function toggleSelected(id) {
  if (selected.value.has(id)) selected.value.delete(id)
  else selected.value.add(id)
  selected.value = new Set(selected.value)
}

const allSelected = computed(() =>
  props.clients.length > 0 && props.clients.every((c) => selected.value.has(c.id))
)

function toggleSelectAll() {
  selected.value = allSelected.value ? new Set() : new Set(props.clients.map((c) => c.id))
}

async function bulkDelete() {
  if (selected.value.size === 0) return
  const count = selected.value.size
  if (!confirm(`¿Eliminar ${count} cliente(s) seleccionado(s) definitivamente? Un cliente con pedidos registrados no se puede eliminar (se puede quitar de esta edicion en su lugar).`)) {
    return
  }

  deleting.value = true
  try {
    const { data } = await axios.post('/clients/bulk-delete', { ids: Array.from(selected.value) })
    toast.success('Eliminacion procesada.')
    selected.value = new Set()
    router.reload({ only: ['clients'] })
  } catch (e) {
    toast.error('No se pudo completar la eliminacion. Verifica tus permisos e intenta de nuevo.')
  } finally {
    deleting.value = false
  }
}

function destroyOne(client) {
  if (!confirm(`¿Eliminar definitivamente a ${client.first_name} ${client.last_name ?? ''}? Esta accion no se puede aplicar si tiene pedidos registrados.`)) return
  router.delete(`/clients/${client.id}`, {
    onSuccess: () => toast.success('Cliente eliminado.'),
  })
}

function removeFromYear(client) {
  if (!confirm(`¿Quitar a ${client.first_name} ${client.last_name ?? ''} de ${props.year.label || props.year.year}? Esto no borra al cliente ni su historial, solo la asignacion de esta edicion.`)) return
  router.delete(`/clients/${client.id}/assignment`, {
    data: { year_id: props.year.id },
    preserveScroll: true,
    onSuccess: () => toast.success('Cliente quitado de la edicion.'),
  })
}

function updateHistoricalNumber(client, value) {
  const historical_number = value === '' ? null : Number(value)
  router.put(`/clients/${client.id}/historical-number`, { historical_number }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => toast.success('Número histórico actualizado.'),
    onError: () => toast.error('No se pudo actualizar (¿ya está en uso?).'),
  })
}

function openCreate() {
  editingClient.value = null
  showFormModal.value = true
}

function openEdit(client) {
  editingClient.value = client
  showFormModal.value = true
}

function onSaved() {
  router.reload({ only: ['clients'] })
}

function selfAssign(client) {
  router.post(`/clients/${client.id}/assignment/self-assign`, { year_id: props.year.id }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Cliente autoasignado.'),
    onError: () => toast.error('No se pudo autoasignar.'),
  })
}

function transfer(client, userId) {
  if (!userId) return
  router.post(`/clients/${client.id}/assignment/transfer`, { assigned_user_id: userId, year_id: props.year.id }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Asignación transferida.'),
    onError: () => toast.error('No se pudo transferir.'),
  })
}

function updateContact(client, patch) {
  const assignment = client.year_assignment
  router.put(`/clients/${client.id}/assignment/contact`, {
    year_id: props.year.id,
    contact_status: patch.contact_status ?? assignment?.contact_status ?? 'pendiente',
    notes: patch.notes ?? assignment?.notes,
    mark_contacted_now: true,
  }, {
    preserveScroll: true,
    onSuccess: () => toast.success('Seguimiento actualizado.'),
    onError: () => toast.error('No se pudo actualizar el seguimiento.'),
  })
}

function selectedAssignmentIds() {
  return props.clients
    .filter((c) => selected.value.has(c.id))
    .map((c) => c.year_assignment?.id)
    .filter(Boolean)
}

function bulkAssign() {
  const assignmentIds = selectedAssignmentIds()
  if (!bulkTargetUser.value || !assignmentIds.length) return
  axios.post('/assignments/bulk-assign', { assignment_ids: assignmentIds, assigned_user_id: bulkTargetUser.value })
    .then(() => {
      toast.success('Asignación masiva realizada.')
      selected.value = new Set()
      router.reload({ only: ['clients'] })
    })
    .catch(() => toast.error('No se pudo asignar en masa.'))
}

function bulkDistribute() {
  const assignmentIds = selectedAssignmentIds()
  if (distributeUsers.value.length < 1 || !assignmentIds.length) return
  axios.post('/assignments/bulk-distribute', { assignment_ids: assignmentIds, user_ids: distributeUsers.value })
    .then(() => {
      toast.success('Reparto equitativo realizado.')
      selected.value = new Set()
      router.reload({ only: ['clients'] })
    })
    .catch(() => toast.error('No se pudo repartir.'))
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
    const { data } = await axios.post('/assignments/generate-preview', {
      from_year_id: generateFromYearId.value,
      to_year_id: props.year.id,
    })
    generatePreview.value = data
  } catch {
    toast.error('No se pudo previsualizar.')
  } finally {
    generateBusy.value = false
  }
}

async function confirmGenerate() {
  generateBusy.value = true
  try {
    const { data } = await axios.post('/assignments/generate', {
      from_year_id: generateFromYearId.value,
      to_year_id: props.year.id,
    })
    toast.success(`Generación completada: ${data.kept_responsible} conservaron responsable, ${data.unassigned_inactive} quedaron sin asignar, ${data.already_existed} ya existían.`)
    showGenerateModal.value = false
    router.reload({ only: ['clients'] })
  } catch {
    toast.error('No se pudo generar.')
  } finally {
    generateBusy.value = false
  }
}

function exportUrl() {
  const params = new URLSearchParams({ year_id: props.year.id })
  return `/assignments/export?${params.toString()}`
}
</script>

<template>
  <Head title="Clientes" />

  <AppLayout title="Clientes">
    <template #header>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 class="font-semibold text-xl text-white leading-tight">Clientes — Edición {{ year.year }}</h2>
        <button
          v-if="can('clientes.crear')"
          type="button"
          class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold"
          @click="openCreate"
        >
          + Nuevo cliente
        </button>
      </div>
    </template>

    <div class="py-6 max-w-7xl mx-auto px-4 space-y-4">
      <YearSelector :selected-year-id="year.id" />

      <div class="flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[16rem]">
          <input
            v-model="search"
            type="text"
            placeholder="Buscar por nombre, apellido, teléfono, N° histórico..."
            class="w-full bg-gray-800 text-white border border-gray-600 rounded-md px-3 py-2 text-sm"
            @keydown.enter="applySearch"
            @focus="showSuggestions = suggestions.length > 0"
            @blur="() => setTimeout(() => (showSuggestions = false), 150)"
          />
          <!-- Fase 7 (correccion 2): sugerencias en tiempo real (sin apretar Enter). -->
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
              Sin resultados.
            </div>
          </div>
        </div>
        <button type="button" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-md text-sm" @click="applySearch">
          Buscar
        </button>
        <a :href="exportUrl()" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-md text-sm">
          Exportar Excel
        </a>
        <button v-if="canGenerate" type="button" class="bg-purple-700 hover:bg-purple-600 text-white px-3 py-2 rounded-md text-sm" @click="openGenerateModal">
          Generar desde edición anterior
        </button>
        <!-- Fase 8 (correccion): filtro rapido "Mis clientes asignados" —
             clientes con assigned_user_id = auth user para la edicion actual. -->
        <button
          type="button"
          class="px-3 py-2 rounded-md text-sm border transition-colors"
          :class="filters.my_assigned_clients
            ? 'bg-indigo-600 text-white border-indigo-500'
            : 'bg-gray-800 text-white border-gray-600 hover:bg-gray-700'"
          @click="reloadWith({ my_assigned_clients: filters.my_assigned_clients ? undefined : '1' })"
        >
          Mis clientes asignados
        </button>
        <button
          v-if="selected.size > 0 && can('clientes.eliminar')"
          type="button"
          :disabled="deleting"
          class="bg-red-900 hover:bg-red-800 text-white disabled:opacity-50 px-4 py-2 rounded-md text-sm"
          @click="bulkDelete"
        >
          Eliminar seleccionados ({{ selected.size }})
        </button>
      </div>

      <!-- Acciones masivas de asignacion (solo Logistica/Admin, seccion 2) -->
      <div v-if="canBulk && selected.size > 0" class="bg-gray-900 text-white rounded-lg p-4 flex flex-wrap items-end gap-3">
        <span class="text-sm text-gray-400">{{ selected.size }} seleccionados</span>
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

      <div class="overflow-x-auto rounded-md border border-gray-700">
        <table class="w-full text-sm bg-gray-900 text-white">
          <thead class="bg-gray-800">
            <tr>
              <th v-if="canBulk" class="p-2 w-8">
                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
              </th>
              <th class="p-2 text-left cursor-pointer select-none" @click="sortBy('historical_number')">N°</th>
              <th class="p-2 text-left cursor-pointer select-none" @click="sortBy('last_name')">
                Apellido
                <span v-if="filters.sort === 'last_name'">{{ filters.direction === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="p-2 text-left cursor-pointer select-none" @click="sortBy('first_name')">
                Nombre
                <span v-if="filters.sort === 'first_name'">{{ filters.direction === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="p-2 text-left cursor-pointer select-none" @click="sortBy('phone')">Teléfono</th>
              <th class="p-2 text-left">Responsable</th>
              <th class="p-2 text-left">Estado</th>
              <th class="p-2 text-left">Último contacto</th>
              <th class="p-2 text-left">Observaciones</th>
              <th class="p-2 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in clients"
              :key="client.id"
              class="border-t border-gray-800 hover:bg-gray-800/60"
            >
              <td v-if="canBulk" class="p-2">
                <input type="checkbox" :checked="selected.has(client.id)" @change="toggleSelected(client.id)" />
              </td>
              <td class="p-2">
                <input
                  v-if="canManageHistoricalNumber"
                  type="number"
                  class="w-16 bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs"
                  :value="client.historical_number"
                  @change="updateHistoricalNumber(client, $event.target.value)"
                />
                <span v-else>{{ client.historical_number ?? '—' }}</span>
              </td>
              <td class="p-2">{{ client.last_name }}</td>
              <td class="p-2">{{ client.first_name }}</td>
              <td class="p-2">{{ client.phone }}</td>
              <td class="p-2">
                <div class="flex items-center gap-1 flex-wrap">
                  <span v-if="client.year_assignment?.assigned_user" class="mr-1">{{ client.year_assignment.assigned_user.name }}</span>
                  <span v-else class="text-yellow-400 mr-1">Sin asignar</span>
                  <button
                    v-if="!client.year_assignment?.assigned_user"
                    type="button"
                    class="text-blue-400 hover:underline text-xs"
                    @click="selfAssign(client)"
                  >
                    Autoasignarme
                  </button>
                  <select
                    v-if="canTransfer"
                    class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs"
                    :value="''"
                    @change="transfer(client, $event.target.value); $event.target.value = ''"
                  >
                    <option value="" disabled>Transferir a...</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
              </td>
              <td class="p-2">
                <select
                  class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs"
                  :value="client.year_assignment?.contact_status ?? 'pendiente'"
                  @change="updateContact(client, { contact_status: $event.target.value })"
                >
                  <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
              </td>
              <td class="p-2 text-xs text-gray-400">
                {{ client.year_assignment?.last_contacted_at ? new Date(client.year_assignment.last_contacted_at).toLocaleString('es-AR') : '—' }}
                <span v-if="client.year_assignment?.last_contacted_by"> ({{ client.year_assignment.last_contacted_by.name }})</span>
              </td>
              <td class="p-2">
                <input
                  type="text"
                  class="bg-gray-800 border border-gray-600 rounded-md px-1 py-0.5 text-xs w-36"
                  :value="client.year_assignment?.notes"
                  placeholder="Observación de seguimiento..."
                  @change="updateContact(client, { notes: $event.target.value })"
                />
              </td>
              <td class="p-2">
                <div class="flex flex-wrap gap-2">
                  <Link :href="`/clients/${client.id}/history`" class="text-blue-400 hover:text-blue-300 text-xs">Historial</Link>
                  <a :href="`/orders/create?client_id=${client.id}&year_id=${year.id}`" class="text-blue-400 hover:text-blue-300 text-xs">Crear pedido</a>
                  <!-- Fase 18.1: un Rover comun (sin 'asignaciones.transferir')
                       solo puede editar los clientes donde el es el
                       responsable asignado en la edicion activa (ver
                       ClientPolicy::update). -->
                  <button
                    v-if="can('clientes.editar') && (canTransfer || client.year_assignment?.assigned_user_id === page.props.auth.user.id)"
                    type="button"
                    class="text-gray-300 hover:text-white text-xs"
                    @click="openEdit(client)"
                  >Editar</button>
                  <button v-if="canTransfer" type="button" class="text-yellow-400 hover:text-yellow-300 text-xs" @click="removeFromYear(client)">Quitar de la edición</button>
                  <button v-if="can('clientes.eliminar')" type="button" class="text-red-400 hover:text-red-300 text-xs" @click="destroyOne(client)">Eliminar</button>
                </div>
              </td>
            </tr>
            <tr v-if="!clients.length">
              <td :colspan="canBulk ? 10 : 9" class="p-8 text-center text-gray-500">
                <template v-if="isFiltering">
                  <p class="text-2xl mb-1">🔎</p>
                  <p>No se encontraron clientes con esa búsqueda o filtro.</p>
                  <p class="text-xs text-gray-500 mt-1">Probá con otro nombre, apellido o teléfono.</p>
                </template>
                <template v-else>
                  <p class="text-2xl mb-1">👥</p>
                  <p class="text-gray-300 font-medium">Todavía no hay clientes cargados.</p>
                  <p class="text-xs mt-1">Sumá el primero para arrancar.</p>
                  <button v-if="can('clientes.crear')" type="button" class="text-blue-400 block mt-2 mx-auto" @click="openCreate">
                    Crear el primer cliente
                  </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ClientFormModal
      :show="showFormModal"
      :client="editingClient"
      @close="showFormModal = false"
      @saved="onSaved"
    />

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

    <ToastContainer />
  </AppLayout>
</template>
