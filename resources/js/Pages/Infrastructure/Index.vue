<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import Modal from '@/Components/Modal.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  team:          { type: String,  required: true },
  year:          { type: Object,  required: true },
  inventoryRows: { type: Array,   required: true },
  loans:         { type: Array,   required: true },
  loanSummary:   { type: Object,  required: true },
  items:         { type: Array,   required: true },
  canManage:     { type: Boolean, required: true },
})

const toast = useToast()

// ═══════════════════════════════════════════════════════════════════════════
// INVENTARIO
// ═══════════════════════════════════════════════════════════════════════════

const rowState = reactive({})
function fieldsOf(row) {
  return {
    needed_quantity:        row.needed_quantity,
    own_available_quantity: row.own_available_quantity,
    own_to_repair_quantity: row.own_to_repair_quantity,
    notes:                  row.notes ?? '',
  }
}
for (const row of props.inventoryRows) rowState[row.id] = fieldsOf(row)
watch(() => props.inventoryRows, (rows) => {
  for (const row of rows) {
    if (!rowState[row.id]) rowState[row.id] = fieldsOf(row)
  }
})

function saveRow(row) {
  router.put(route('infrastructure.inventory.update', { team: props.team, inventory: row.id }), rowState[row.id], {
    preserveScroll: true,
    preserveState: true,
    onError: () => toast.error('Error al guardar el elemento.'),
  })
}

function deleteRow(row) {
  if (!window.confirm(
    `¿Quitar "${row.item.name}" del inventario de esta edición?\n\n` +
    'Esto no elimina el elemento: seguirá disponible en el catálogo para agregarlo en esta u otras ediciones.'
  )) return
  router.delete(route('infrastructure.inventory.destroy', { team: props.team, inventory: row.id }), {
    preserveScroll: true,
    onSuccess: () => toast.success('Elemento quitado de esta edición.'),
  })
}

function statusLabel(row) {
  if (row.status === 'complete') return 'Completo'
  if (row.status === 'surplus') return `Excedente +${row.diff_quantity}`
  return `Faltan ${row.diff_quantity}`
}
function statusClass(row) {
  if (row.status === 'complete') return 'bg-green-100 text-green-700'
  if (row.status === 'surplus') return 'bg-blue-100 text-blue-700'
  return 'bg-red-100 text-red-700'
}

// ─── Agregar elemento al inventario ─────────────────────────────────────────
const showNewRow = ref(false)
const useNewItem = ref(false)

const newRowForm = useForm({
  infrastructure_item_id: '',
  new_item_name: '',
  needed_quantity: '',
  own_available_quantity: '',
  own_to_repair_quantity: '',
  year_id: props.year.id,
})

const existingItemIds = computed(() => new Set(props.inventoryRows.map((r) => r.infrastructure_item_id)))
const availableItems = computed(() => props.items.filter((i) => i.is_active && !existingItemIds.value.has(i.id)))

function openNewRow() {
  newRowForm.reset()
  newRowForm.year_id = props.year.id
  useNewItem.value = false
  showNewRow.value = true
}

function submitNewRow() {
  if (useNewItem.value) {
    newRowForm.infrastructure_item_id = ''
    if (!newRowForm.new_item_name.trim()) return
  } else {
    newRowForm.new_item_name = ''
    if (!newRowForm.infrastructure_item_id) return
  }

  newRowForm.post(route('infrastructure.inventory.store', props.team), {
    preserveScroll: true,
    onSuccess: () => { showNewRow.value = false; newRowForm.reset() },
    onError: () => toast.error('Error al agregar el elemento.'),
  })
}

const selectedExistingItem = computed(() =>
  newRowForm.infrastructure_item_id
    ? props.items.find((i) => i.id === Number(newRowForm.infrastructure_item_id))
    : null
)

// ─── Editar elemento del catálogo ───────────────────────────────────────────
const editingItem = ref(null)
const editItemForm = useForm({ name: '', description: '' })

function openEditItem(item) {
  editingItem.value = item
  editItemForm.reset()
  editItemForm.clearErrors()
  editItemForm.name = item.name
  editItemForm.description = item.description ?? ''
}
function closeEditItem() {
  editingItem.value = null
}
function submitEditItem() {
  if (!editItemForm.name.trim()) return
  editItemForm.put(route('infrastructure.items.update', { team: props.team, item: editingItem.value.id }), {
    preserveScroll: true,
    onSuccess: () => { closeEditItem(); toast.success('Elemento actualizado.') },
    onError: () => toast.error('Error al actualizar el elemento.'),
  })
}

// ═══════════════════════════════════════════════════════════════════════════
// PRÉSTAMOS
// ═══════════════════════════════════════════════════════════════════════════

const loanFilter = ref('all') // all | pending | returned
const loanSearch = ref('')

const filteredLoans = computed(() => {
  let list = props.loans
  if (loanFilter.value !== 'all') list = list.filter((l) => l.status === loanFilter.value)
  if (loanSearch.value.trim()) {
    const q = loanSearch.value.trim().toLowerCase()
    list = list.filter((l) => l.item.name.toLowerCase().includes(q) || l.lender.toLowerCase().includes(q))
  }
  return list
})

function fmtDate(str) {
  if (!str) return ''
  const [y, m, d] = String(str).split('T')[0].split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' })
}

const showNewLoan = ref(false)
const newLoanForm = useForm({
  infrastructure_item_id: '',
  quantity: '',
  lender: '',
  notes: '',
  year_id: props.year.id,
})

function openNewLoan() {
  newLoanForm.reset()
  newLoanForm.year_id = props.year.id
  showNewLoan.value = true
}

function submitNewLoan() {
  if (!newLoanForm.infrastructure_item_id || !newLoanForm.lender.trim() || !newLoanForm.quantity) return
  newLoanForm.post(route('infrastructure.loans.store', props.team), {
    preserveScroll: true,
    onSuccess: () => { showNewLoan.value = false; newLoanForm.reset() },
    onError: () => toast.error('Error al registrar el préstamo.'),
  })
}

const editingLoanId = ref(null)
const editLoanForm = useForm({ quantity: '', lender: '', notes: '' })

function startEditLoan(loan) {
  editingLoanId.value = loan.id
  editLoanForm.reset()
  editLoanForm.clearErrors()
  editLoanForm.quantity = loan.quantity
  editLoanForm.lender = loan.lender
  editLoanForm.notes = loan.notes ?? ''
}
function cancelEditLoan() {
  editingLoanId.value = null
}
function saveEditLoan(loan) {
  if (!editLoanForm.lender.trim() || !editLoanForm.quantity) return
  editLoanForm.put(route('infrastructure.loans.update', { team: props.team, loan: loan.id }), {
    preserveScroll: true,
    onSuccess: () => cancelEditLoan(),
    onError: () => toast.error('Error al actualizar el préstamo.'),
  })
}

function deleteLoan(loan) {
  if (!window.confirm('¿Eliminar este préstamo?')) return
  router.delete(route('infrastructure.loans.destroy', { team: props.team, loan: loan.id }), {
    preserveScroll: true,
    onSuccess: () => toast.success('Préstamo eliminado.'),
  })
}

function markReturned(loan) {
  router.post(route('infrastructure.loans.status', { team: props.team, loan: loan.id }), { status: 'returned' }, { preserveScroll: true })
}
function markPending(loan) {
  router.post(route('infrastructure.loans.status', { team: props.team, loan: loan.id }), { status: 'pending' }, { preserveScroll: true })
}

const editingReturnDateId = ref(null)
const returnDateValue = ref('')
function openReturnDate(loan) {
  editingReturnDateId.value = loan.id
  returnDateValue.value = loan.returned_at ? String(loan.returned_at).split('T')[0] : ''
}
function cancelReturnDate() {
  editingReturnDateId.value = null
}
function saveReturnDate(loan) {
  router.post(route('infrastructure.loans.status', { team: props.team, loan: loan.id }), {
    status: 'returned',
    returned_at: returnDateValue.value || null,
  }, { preserveScroll: true, onSuccess: () => { editingReturnDateId.value = null } })
}
</script>

<template>
  <Head :title="`Inventario de infraestructura — Edición ${year.year}`" />
  <AppLayout :title="`Inventario de infraestructura — Edición ${year.year}`">
    <template #header>
      <div class="flex items-center gap-4">
        <a :href="route('teams.show', team)" class="text-xs text-indigo-600 hover:text-indigo-800 uppercase tracking-wide">
          ← Infraestructura
        </a>
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Inventario de infraestructura</h2>
      </div>
    </template>

    <div class="py-8 max-w-7xl mx-auto px-4 space-y-10">

      <!-- Año + navegación -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <YearSelector :selected-year-id="year.id" />
        <a v-if="canManage" :href="route('infrastructure.import', { team, target_year_id: year.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
          Importar desde otra edición
        </a>
      </div>

      <!-- ═══════════ INVENTARIO GENERAL ═══════════ -->
      <div>
        <h3 class="font-semibold text-gray-700 mb-4">Inventario general</h3>

        <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
          <table class="min-w-[900px] w-full text-sm">
            <thead>
              <tr class="border-b border-gray-200 text-xs text-gray-400 uppercase tracking-wide">
                <th class="text-left px-3 py-2 font-medium">Elemento</th>
                <th class="text-center px-2 py-2 font-medium w-20">Necesarias</th>
                <th class="text-center px-2 py-2 font-medium w-20">Nuestras</th>
                <th class="text-center px-2 py-2 font-medium w-20">En reparación</th>
                <th class="text-center px-2 py-2 font-medium w-24">Prestadas activas</th>
                <th class="text-center px-2 py-2 font-medium w-20">Total útil</th>
                <th class="text-left px-3 py-2 font-medium w-32">Estado</th>
                <th class="text-left px-3 py-2 font-medium w-40">Observaciones</th>
                <th v-if="canManage" class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in inventoryRows" :key="row.id" class="border-b border-gray-50 last:border-b-0 align-top">
                <td class="px-3 py-2 font-medium text-gray-800 whitespace-nowrap">
                  {{ row.item.name }}
                  <button
                    v-if="canManage"
                    type="button"
                    class="ml-1 text-gray-300 hover:text-indigo-600 text-xs align-middle"
                    title="Editar elemento del catálogo (nombre, descripción)"
                    @click="openEditItem(row.item)"
                  >✎</button>
                </td>

                <template v-if="canManage">
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[row.id].needed_quantity" type="number" step="1" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveRow(row)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[row.id].own_available_quantity" type="number" step="1" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveRow(row)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[row.id].own_to_repair_quantity" type="number" step="1" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveRow(row)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-2 py-2 text-center text-gray-400 tabular-nums">{{ row.active_loans_quantity }}</td>
                  <td class="px-2 py-2 text-center text-gray-700 font-medium tabular-nums">{{ row.total_useful_quantity }}</td>
                  <td class="px-3 py-2">
                    <div class="flex flex-col gap-1 items-start">
                      <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(row)">
                        {{ statusLabel(row) }}
                      </span>
                      <span v-if="row.own_to_repair_quantity > 0" class="text-xs text-amber-600">
                        ⚠ {{ row.own_to_repair_quantity }} en reparación
                      </span>
                    </div>
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[row.id].notes" type="text" placeholder="Observaciones"
                      class="w-full text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveRow(row)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5 text-center">
                    <button type="button" class="text-gray-300 hover:text-red-500 text-xs"
                      title="Quitar de esta edición (el elemento sigue disponible en el catálogo)"
                      @click="deleteRow(row)">✕</button>
                  </td>
                </template>

                <template v-else>
                  <td class="px-2 py-2 text-center text-gray-600">{{ row.needed_quantity }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ row.own_available_quantity }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ row.own_to_repair_quantity }}</td>
                  <td class="px-2 py-2 text-center text-gray-400 tabular-nums">{{ row.active_loans_quantity }}</td>
                  <td class="px-2 py-2 text-center text-gray-700 font-medium tabular-nums">{{ row.total_useful_quantity }}</td>
                  <td class="px-3 py-2">
                    <div class="flex flex-col gap-1 items-start">
                      <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(row)">
                        {{ statusLabel(row) }}
                      </span>
                      <span v-if="row.own_to_repair_quantity > 0" class="text-xs text-amber-600">
                        ⚠ {{ row.own_to_repair_quantity }} en reparación
                      </span>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-gray-500">{{ row.notes ?? '—' }}</td>
                </template>
              </tr>
            </tbody>
          </table>

          <p v-if="inventoryRows.length === 0" class="text-center text-gray-400 py-10 text-sm">
            No hay elementos en el inventario de esta edición.
          </p>
        </div>

        <!-- Agregar elemento -->
        <div v-if="canManage" class="mt-4">
          <button v-if="!showNewRow" type="button" class="text-sm text-indigo-600 hover:text-indigo-800" @click="openNewRow">
            + Agregar elemento
          </button>

          <!-- NO es <form>: evita que Enter en un campo intermedio dispare el submit antes de tiempo. -->
          <div v-else class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 space-y-3">
            <div class="flex items-center gap-4 text-xs">
              <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="radio" :checked="!useNewItem" @change="useNewItem = false" class="text-indigo-600" />
                Elemento existente
              </label>
              <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="radio" :checked="useNewItem" @change="useNewItem = true" class="text-indigo-600" />
                Nuevo elemento
              </label>
            </div>

            <div v-if="!useNewItem">
              <label class="block text-xs font-medium text-gray-700 mb-1">Elemento</label>
              <div class="flex items-center gap-2">
                <select v-model="newRowForm.infrastructure_item_id"
                  class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                  <option value="">Seleccionar elemento…</option>
                  <option v-for="i in availableItems" :key="i.id" :value="i.id">{{ i.name }}</option>
                </select>
                <button v-if="selectedExistingItem" type="button" class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap"
                  @click="openEditItem(selectedExistingItem)">
                  ✎ Editar elemento
                </button>
              </div>
              <p v-if="newRowForm.errors.infrastructure_item_id" class="text-xs text-red-600 mt-1">{{ newRowForm.errors.infrastructure_item_id }}</p>
            </div>

            <div v-else>
              <label class="block text-xs font-medium text-gray-700 mb-1">Nombre del elemento *</label>
              <input v-model="newRowForm.new_item_name" type="text" placeholder="Ej: Hornallón simple"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent />
              <p v-if="newRowForm.errors.new_item_name" class="text-xs text-red-600 mt-1">{{ newRowForm.errors.new_item_name }}</p>
            </div>

            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Cantidad necesaria</label>
                <input v-model="newRowForm.needed_quantity" type="number" step="1" min="0"
                  class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Cantidad nuestra</label>
                <input v-model="newRowForm.own_available_quantity" type="number" step="1" min="0"
                  class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">En reparación</label>
                <input v-model="newRowForm.own_to_repair_quantity" type="number" step="1" min="0"
                  class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
                <p v-if="newRowForm.errors.own_to_repair_quantity" class="text-xs text-red-600 mt-1">{{ newRowForm.errors.own_to_repair_quantity }}</p>
              </div>
            </div>

            <div class="flex gap-2 pt-1">
              <button type="button" :disabled="newRowForm.processing" @click="submitNewRow"
                class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                Agregar
              </button>
              <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showNewRow = false">
                Cancelar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ PRÉSTAMOS ═══════════ -->
      <div>
        <h3 class="font-semibold text-gray-700 mb-2">Préstamos</h3>
        <p class="text-sm text-gray-500 mb-4">
          {{ loanSummary.active_units }} unidad{{ loanSummary.active_units === 1 ? '' : 'es' }} prestada{{ loanSummary.active_units === 1 ? '' : 's' }}
          · {{ loanSummary.pending_lenders }} prestamista{{ loanSummary.pending_lenders === 1 ? '' : 's' }}
          · {{ loanSummary.active_count }} préstamo{{ loanSummary.active_count === 1 ? '' : 's' }} pendiente{{ loanSummary.active_count === 1 ? '' : 's' }} de devolución
        </p>

        <!-- Filtros -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
          <button type="button" class="px-3 py-1 rounded-full text-xs font-medium"
            :class="loanFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
            @click="loanFilter = 'all'">Todos</button>
          <button type="button" class="px-3 py-1 rounded-full text-xs font-medium"
            :class="loanFilter === 'pending' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
            @click="loanFilter = 'pending'">Pendientes</button>
          <button type="button" class="px-3 py-1 rounded-full text-xs font-medium"
            :class="loanFilter === 'returned' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
            @click="loanFilter = 'returned'">Devueltos</button>
          <input v-model="loanSearch" type="text" placeholder="Buscar elemento o prestamista..."
            class="ml-auto w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        </div>

        <div class="space-y-2">
          <div v-for="loan in filteredLoans" :key="loan.id" class="bg-white rounded-lg shadow-sm border border-gray-100 p-3">

            <template v-if="editingLoanId !== loan.id">
              <div class="flex items-start gap-3">
                <span class="flex-shrink-0 pt-0.5 font-mono text-base" :class="loan.status === 'returned' ? 'text-green-600' : 'text-amber-500'">
                  {{ loan.status === 'returned' ? '✓' : '○' }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800">
                    {{ loan.item.name }}
                    <span class="text-gray-400 font-normal">· cantidad {{ loan.quantity }}</span>
                  </p>
                  <p class="text-xs text-gray-500 mt-0.5">Prestado por <strong class="text-gray-700">{{ loan.lender }}</strong></p>
                  <p v-if="loan.notes" class="text-xs text-amber-700 italic mt-1 border-l-2 border-amber-200 pl-2">{{ loan.notes }}</p>

                  <p v-if="loan.status === 'returned'" class="text-xs text-green-700 mt-1">
                    Devuelto<template v-if="loan.returned_at"> el {{ fmtDate(loan.returned_at) }}</template>
                  </p>

                  <!-- Corrección de fecha de devolución -->
                  <div v-if="editingReturnDateId === loan.id" class="mt-2 flex items-center gap-2">
                    <input v-model="returnDateValue" type="date" class="text-xs rounded border-gray-300 focus:border-indigo-400 focus:ring-indigo-400" />
                    <button type="button" class="text-xs text-indigo-600 hover:text-indigo-800" @click="saveReturnDate(loan)">Guardar</button>
                    <button type="button" class="text-xs text-gray-400 hover:text-gray-600" @click="cancelReturnDate">Cancelar</button>
                  </div>

                  <!-- Acciones -->
                  <div v-if="canManage" class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
                    <template v-if="loan.status === 'pending'">
                      <button type="button" class="text-xs font-medium text-green-600 hover:text-green-800" @click="markReturned(loan)">
                        Marcar devuelto
                      </button>
                    </template>
                    <template v-else>
                      <button type="button" class="text-xs text-gray-300 hover:text-gray-500" @click="markPending(loan)">
                        Volver a pendiente
                      </button>
                      <button v-if="editingReturnDateId !== loan.id" type="button" class="text-xs text-gray-300 hover:text-indigo-600" @click="openReturnDate(loan)">
                        {{ loan.returned_at ? 'Corregir fecha' : '+ Agregar fecha' }}
                      </button>
                    </template>
                    <span class="text-gray-200">|</span>
                    <button type="button" class="text-xs text-gray-300 hover:text-gray-600" @click="startEditLoan(loan)">Editar</button>
                    <button type="button" class="text-xs text-gray-300 hover:text-red-500" @click="deleteLoan(loan)">Eliminar</button>
                  </div>
                </div>
              </div>
            </template>

            <template v-else>
              <div class="space-y-3">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ loan.item.name }}</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Cantidad *</label>
                    <input v-model="editLoanForm.quantity" type="number" step="1" min="1"
                      class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Prestado por *</label>
                    <input v-model="editLoanForm.lender" type="text"
                      class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-700 mb-1">Observaciones</label>
                  <textarea v-model="editLoanForm.notes" rows="2" placeholder="Ej: cinta roja en las manijas"
                    class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
                <div class="flex gap-2">
                  <button type="button" class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700" @click="saveEditLoan(loan)">
                    Guardar
                  </button>
                  <button type="button" class="px-3 py-1.5 text-xs text-gray-600 hover:text-gray-900" @click="cancelEditLoan">
                    Cancelar
                  </button>
                </div>
              </div>
            </template>
          </div>

          <p v-if="filteredLoans.length === 0" class="text-center text-gray-400 py-10 text-sm">
            {{ loans.length === 0 ? 'No hay préstamos registrados para esta edición.' : 'No se encontraron préstamos.' }}
          </p>
        </div>

        <!-- Registrar préstamo -->
        <div v-if="canManage" class="mt-4">
          <button v-if="!showNewLoan" type="button" class="text-sm text-indigo-600 hover:text-indigo-800" @click="openNewLoan">
            + Registrar préstamo
          </button>

          <div v-else class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Elemento *</label>
              <select v-model="newLoanForm.infrastructure_item_id"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">Seleccionar elemento…</option>
                <option v-for="i in items.filter(x => x.is_active)" :key="i.id" :value="i.id">{{ i.name }}</option>
              </select>
              <p v-if="newLoanForm.errors.infrastructure_item_id" class="text-xs text-red-600 mt-1">{{ newLoanForm.errors.infrastructure_item_id }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Cantidad *</label>
                <input v-model="newLoanForm.quantity" type="number" step="1" min="1"
                  class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
                <p v-if="newLoanForm.errors.quantity" class="text-xs text-red-600 mt-1">{{ newLoanForm.errors.quantity }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Prestado por *</label>
                <input v-model="newLoanForm.lender" type="text" placeholder="Ej: Grupo San José"
                  class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
                <p v-if="newLoanForm.errors.lender" class="text-xs text-red-600 mt-1">{{ newLoanForm.errors.lender }}</p>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Observaciones (identificación física, etc.)</label>
              <textarea v-model="newLoanForm.notes" rows="2" placeholder="Ej: cinta roja en las manijas"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <div class="flex gap-2 pt-1">
              <button type="button" :disabled="newLoanForm.processing" @click="submitNewLoan"
                class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                Registrar
              </button>
              <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showNewLoan = false">
                Cancelar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Editar elemento del catálogo -->
      <Modal :show="editingItem !== null" @close="closeEditItem">
        <div class="p-6" v-if="editingItem">
          <h2 class="text-lg font-medium text-gray-900 mb-1">Editar elemento</h2>
          <p class="text-xs text-gray-400 mb-4">
            Esto corrige el elemento en el catálogo compartido entre ediciones, no solo en esta edición.
          </p>

          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Nombre *</label>
              <input v-model="editItemForm.name" type="text"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent="submitEditItem" />
              <p v-if="editItemForm.errors.name" class="text-xs text-red-600 mt-1">{{ editItemForm.errors.name }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Descripción / observaciones generales (opcional)</label>
              <textarea v-model="editItemForm.description" rows="2"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>

          <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="closeEditItem">
              Cancelar
            </button>
            <button type="button" :disabled="editItemForm.processing || !editItemForm.name.trim()"
              class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50"
              @click="submitEditItem">
              Guardar
            </button>
          </div>
        </div>
      </Modal>

    </div>
  </AppLayout>
</template>
