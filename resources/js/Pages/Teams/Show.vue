<script setup>
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed, reactive } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  team:      { type: String,  required: true },
  tasks:     { type: Array,   required: true },
  documents: { type: Array,   required: true },
  year:      { type: Object,  required: true },
  canManage: { type: Boolean, required: true },
  canImport: { type: Boolean, default: false },
})

const TEAM_LABELS = {
  logistica: 'Logística',
  compras: 'Compras',
  infraestructura: 'Infraestructura',
  publicidad: 'Publicidad',
}

const toast = useToast()
const teamLabel = TEAM_LABELS[props.team] ?? props.team

// ---------- Formato de fecha en español (timezone-safe) --------------------
// Usamos constructor Date(y, m-1, d) para evitar desfase UTC.

function formatDate(dateStr) {
  if (!dateStr) return null
  const [y, m, d] = String(dateStr).split('T')[0].split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-AR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

// ---------- Contadores globales --------------------------------------------

const completedCount = computed(() => props.tasks.filter((t) => t.is_completed).length)
const totalCount = computed(() => props.tasks.length)

// ---------- Estado de fechas (comparación YYYY-MM-DD) ---------------------

const todayStr = new Date().toISOString().split('T')[0]

function taskStatus(task) {
  if (task.is_completed) return 'completed'
  const due = task.due_date ? String(task.due_date).split('T')[0] : null
  const opt = task.optimal_date ? String(task.optimal_date).split('T')[0] : null
  if (due && todayStr > due) return 'overdue'
  if (opt && todayStr > opt) return 'warning'
  return 'normal'
}

function statusClasses(task) {
  const s = taskStatus(task)
  if (s === 'completed') return 'border-l-4 border-green-400 bg-white'
  if (s === 'overdue')   return 'border-l-4 border-red-400 bg-white'
  if (s === 'warning')   return 'border-l-4 border-yellow-400 bg-white'
  return 'border-l-4 border-gray-200 bg-white'
}

// ---------- Expandible ----------------------------------------------------

const expandedIds = ref(new Set())

function isExpanded(id) {
  return expandedIds.value.has(id)
}

function toggleExpand(id) {
  const s = new Set(expandedIds.value)
  if (s.has(id)) {
    s.delete(id)
    if (editingTaskId.value === id) cancelEditTask()
  } else {
    s.add(id)
  }
  expandedIds.value = s
}

function ensureExpanded(id) {
  const s = new Set(expandedIds.value)
  s.add(id)
  expandedIds.value = s
}

// ---------- Progreso de subtareas -----------------------------------------

function itemProgress(task) {
  if (!task.items || !task.items.length) return null
  const done = task.items.filter((i) => i.is_completed).length
  return { done, total: task.items.length }
}

// ---------- Crear tarea ---------------------------------------------------

const newTitle = ref('')
const newDescription = ref('')
const newNotes = ref('')
const newOptimalDate = ref('')
const newDueDate = ref('')
const showCreateDetails = ref(false)

function addTask() {
  if (!newTitle.value.trim()) return
  router.post(route('teams.tasks.store', props.team), {
    title: newTitle.value,
    description: newDescription.value || null,
    notes: newNotes.value || null,
    optimal_date: newOptimalDate.value || null,
    due_date: newDueDate.value || null,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      newTitle.value = ''
      newDescription.value = ''
      newNotes.value = ''
      newOptimalDate.value = ''
      newDueDate.value = ''
      showCreateDetails.value = false
    },
    onError: () => toast.error('Error al crear la tarea.'),
  })
}

// ---------- Editar tarea (desde el panel expandido) -----------------------

const editingTaskId = ref(null)
const editTaskForm = ref({})

function startEditTask(task) {
  editingTaskId.value = task.id
  ensureExpanded(task.id)
  editTaskForm.value = {
    title: task.title,
    description: task.description ?? '',
    notes: task.notes ?? '',
    optimal_date: task.optimal_date ? String(task.optimal_date).split('T')[0] : '',
    due_date: task.due_date ? String(task.due_date).split('T')[0] : '',
  }
}

function cancelEditTask() {
  editingTaskId.value = null
  editTaskForm.value = {}
}

function saveEditTask(task) {
  if (!editTaskForm.value.title?.trim()) return
  router.put(route('teams.tasks.update', { team: props.team, task: task.id }), {
    title: editTaskForm.value.title,
    description: editTaskForm.value.description || null,
    notes: editTaskForm.value.notes || null,
    optimal_date: editTaskForm.value.optimal_date || null,
    due_date: editTaskForm.value.due_date || null,
  }, {
    preserveScroll: true,
    onSuccess: () => cancelEditTask(),
    onError: () => toast.error('Error al actualizar la tarea.'),
  })
}

// ---------- Toggle tarea --------------------------------------------------

function toggle(task) {
  router.post(route('teams.tasks.toggle', { team: props.team, task: task.id }), {}, {
    preserveScroll: true,
  })
}

// ---------- Eliminar tarea ------------------------------------------------

function destroy(task) {
  router.delete(route('teams.tasks.destroy', { team: props.team, task: task.id }), {
    preserveScroll: true,
    onSuccess: () => toast.success('Tarea eliminada.'),
  })
}

// ---------- Subtareas: crear ----------------------------------------------

const newItemTitles = reactive({})

function addItem(task) {
  const title = (newItemTitles[task.id] ?? '').trim()
  if (!title) return
  router.post(route('teams.task-items.store', { team: props.team, task: task.id }), { title }, {
    preserveScroll: true,
    onSuccess: () => { newItemTitles[task.id] = '' },
    onError: () => toast.error('Error al agregar el paso.'),
  })
}

// ---------- Subtareas: toggle ---------------------------------------------

function toggleItem(task, item) {
  router.post(
    route('teams.task-items.toggle', { team: props.team, task: task.id, item: item.id }),
    {},
    { preserveScroll: true },
  )
}

// ---------- Subtareas: editar ---------------------------------------------

const editingItemId = ref(null)
const editItemTitle = ref('')

function startEditItem(item) {
  editingItemId.value = item.id
  editItemTitle.value = item.title
}

function cancelEditItem() {
  editingItemId.value = null
  editItemTitle.value = ''
}

function saveEditItem(task, item) {
  if (!editItemTitle.value.trim()) return
  router.put(
    route('teams.task-items.update', { team: props.team, task: task.id, item: item.id }),
    { title: editItemTitle.value },
    {
      preserveScroll: true,
      onSuccess: () => cancelEditItem(),
      onError: () => toast.error('Error al editar el paso.'),
    },
  )
}

// ---------- Subtareas: eliminar -------------------------------------------

function destroyItem(task, item) {
  router.delete(
    route('teams.task-items.destroy', { team: props.team, task: task.id, item: item.id }),
    {
      preserveScroll: true,
      onSuccess: () => toast.success('Paso eliminado.'),
    },
  )
}

// ========== DOCUMENTOS ====================================================

// ---------- Helpers de presentación ----------------------------------------

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

function docIcon(mimeType) {
  if (!mimeType) return '📎'
  if (mimeType.includes('pdf')) return '📄'
  if (mimeType.includes('spreadsheet') || mimeType.includes('excel') || mimeType.includes('csv')) return '📊'
  if (mimeType.includes('image')) return '🖼️'
  if (mimeType.includes('word') || mimeType.includes('document')) return '📝'
  if (mimeType.includes('video')) return '🎬'
  return '📎'
}

// ---------- Subir documento -----------------------------------------------

const showUploadForm = ref(false)
const uploadForm = useForm({ name: '', description: '', file: null, year_id: null })

function onFileSelected(e) {
  uploadForm.file = e.target.files[0] ?? null
  if (uploadForm.file && !uploadForm.name.trim()) {
    uploadForm.name = uploadForm.file.name.replace(/\.[^.]+$/, '')
  }
}

function submitUpload() {
  uploadForm.year_id = props.year.id
  uploadForm.post(route('teams.documents.store', props.team), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      uploadForm.reset()
      showUploadForm.value = false
    },
  })
}

// ---------- Editar documento -----------------------------------------------

const editingDocId = ref(null)
const editDocForm = reactive({ name: '', description: '' })

function startEditDoc(doc) {
  editingDocId.value = doc.id
  editDocForm.name = doc.name
  editDocForm.description = doc.description ?? ''
}

function cancelEditDoc() {
  editingDocId.value = null
}

function saveEditDoc(doc) {
  if (!editDocForm.name.trim()) return
  router.put(
    route('teams.documents.update', { team: props.team, doc: doc.id }),
    { name: editDocForm.name, description: editDocForm.description || null },
    {
      preserveScroll: true,
      onSuccess: () => cancelEditDoc(),
      onError: () => toast.error('Error al actualizar el documento.'),
    },
  )
}

// ---------- Eliminar documento --------------------------------------------

function destroyDoc(doc) {
  // eslint-disable-next-line no-alert
  if (!window.confirm(`¿Eliminar "${doc.name}"? Esta acción no se puede deshacer.`)) return
  router.delete(
    route('teams.documents.destroy', { team: props.team, doc: doc.id }),
    {
      preserveScroll: true,
      onSuccess: () => toast.success('Documento eliminado.'),
    },
  )
}
</script>

<template>
  <Head :title="`${teamLabel} — Edición ${year.year}`" />
  <AppLayout :title="`${teamLabel} — Edición ${year.year}`">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ teamLabel }} — Edición {{ year.year }}
      </h2>
    </template>

    <div class="py-8 max-w-3xl mx-auto px-4">

      <!-- Selector de año -->
      <div class="mb-6">
        <YearSelector :selected-year-id="year.id" />
      </div>

      <!-- Fase 14: accesos a planificación de compras y proveedores (solo equipo Compras) -->
      <div
        v-if="team === 'compras' && $page.props.permissions?.includes('compras.planificacion.ver')"
        class="mb-6 flex flex-wrap gap-3"
      >
        <a
          :href="route('purchases.index', { team, year_id: year.id })"
          class="flex-1 min-w-[220px] bg-white rounded-lg shadow-sm border border-gray-100 px-4 py-3 hover:border-indigo-200 hover:shadow transition"
        >
          <p class="text-sm font-semibold text-gray-800">📦 Planificación de compras</p>
          <p class="text-xs text-gray-400 mt-0.5">Productos, cantidades, precios y proveedores por edición</p>
        </a>
        <a
          :href="route('suppliers.index', { team })"
          class="flex-1 min-w-[220px] bg-white rounded-lg shadow-sm border border-gray-100 px-4 py-3 hover:border-indigo-200 hover:shadow transition"
        >
          <p class="text-sm font-semibold text-gray-800">🏬 Proveedores</p>
          <p class="text-xs text-gray-400 mt-0.5">Lugares de compra reutilizables entre ediciones</p>
        </a>
      </div>

      <!-- Fase 15: acceso al inventario de infraestructura (solo ese equipo) -->
      <div
        v-if="team === 'infraestructura' && $page.props.permissions?.includes('infraestructura.inventario.ver')"
        class="mb-6 flex flex-wrap gap-3"
      >
        <a
          :href="route('infrastructure.index', { team, year_id: year.id })"
          class="flex-1 min-w-[220px] bg-white rounded-lg shadow-sm border border-gray-100 px-4 py-3 hover:border-indigo-200 hover:shadow transition"
        >
          <p class="text-sm font-semibold text-gray-800">🧰 Inventario de infraestructura</p>
          <p class="text-xs text-gray-400 mt-0.5">Elementos propios, necesidades, reparaciones y préstamos por edición</p>
        </a>
      </div>

      <!-- Fase 16: acceso a publicidad histórica (solo ese equipo) -->
      <div v-if="team === 'publicidad'" class="mb-6 flex flex-wrap gap-3">
        <a
          :href="route('publicity.index', { team, year_id: year.id })"
          class="flex-1 min-w-[220px] bg-white rounded-lg shadow-sm border border-gray-100 px-4 py-3 hover:border-indigo-200 hover:shadow transition"
        >
          <p class="text-sm font-semibold text-gray-800">📣 Publicidad histórica</p>
          <p class="text-xs text-gray-400 mt-0.5">Flyers, historias, reels y folletos archivados por edición</p>
        </a>
      </div>

      <!-- Fase 17: acceso a logística histórica (solo ese equipo) -->
      <div v-if="team === 'logistica'" class="mb-6 flex flex-wrap gap-3">
        <a
          :href="route('logistics.index', { team, year_id: year.id })"
          class="flex-1 min-w-[220px] bg-white rounded-lg shadow-sm border border-gray-100 px-4 py-3 hover:border-indigo-200 hover:shadow transition"
        >
          <p class="text-sm font-semibold text-gray-800">🚚 Logística histórica</p>
          <p class="text-xs text-gray-400 mt-0.5">Recorridos, mapas, exportaciones y listados archivados por edición</p>
        </a>
      </div>

      <!-- Encabezado del checklist -->
      <div class="mb-4 flex items-center justify-between gap-4">
        <h3 class="font-semibold text-gray-700 shrink-0">Checklist de tareas</h3>
        <div class="flex items-center gap-4 min-w-0">
          <span v-if="totalCount > 0" class="text-sm text-gray-500 shrink-0">
            {{ completedCount }} de {{ totalCount }} tareas completadas
          </span>
          <a
            v-if="canImport"
            :href="route('teams.import', { team: team, target_year_id: year.id })"
            class="text-xs text-indigo-500 hover:text-indigo-700 hover:underline shrink-0 whitespace-nowrap"
          >
            Importar desde otra edición
          </a>
        </div>
      </div>

      <!-- Formulario crear tarea -->
      <form
        v-if="canManage"
        @submit.prevent="addTask"
        class="mb-6 bg-white rounded-lg shadow-sm p-4 border border-gray-100"
      >
        <div class="flex gap-2 mb-2">
          <input
            v-model="newTitle"
            type="text"
            placeholder="Nueva tarea..."
            class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
          />
          <button
            type="submit"
            class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700"
          >
            Agregar
          </button>
        </div>
        <button
          type="button"
          class="text-xs text-indigo-600 hover:underline"
          @click="showCreateDetails = !showCreateDetails"
        >
          {{ showCreateDetails ? '▴ Ocultar detalles' : '▾ Agregar detalles' }}
        </button>
        <div v-if="showCreateDetails" class="mt-3 space-y-3">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Descripción</label>
            <textarea
              v-model="newDescription"
              rows="2"
              placeholder="Descripción de la tarea (opcional)"
              class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Observaciones</label>
            <textarea
              v-model="newNotes"
              rows="2"
              placeholder="Notas internas (opcional)"
              class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Fecha óptima</label>
              <input
                v-model="newOptimalDate"
                type="date"
                class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Fecha límite</label>
              <input
                v-model="newDueDate"
                type="date"
                class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
          </div>
        </div>
      </form>

      <!-- Lista de tareas -->
      <div v-if="tasks.length" class="space-y-2">
        <div
          v-for="task in tasks"
          :key="task.id"
          class="rounded-lg shadow-sm"
          :class="statusClasses(task)"
        >

          <!-- ── Fila compacta ──────────────────────────────────────── -->
          <div class="flex items-center gap-3 px-4 py-3">

            <!-- Checkbox de la tarea: stop para no propagar al título -->
            <div class="shrink-0" @click.stop>
              <input
                v-if="canManage"
                type="checkbox"
                :checked="task.is_completed"
                class="rounded border-gray-300 text-indigo-600 cursor-pointer"
                @change="toggle(task)"
              />
              <span v-else class="text-base leading-none">{{ task.is_completed ? '✅' : '⬜' }}</span>
            </div>

            <!-- Título (botón clickeable → expand/collapse) -->
            <button
              type="button"
              class="flex-1 text-left text-sm font-medium min-w-0 truncate"
              :class="task.is_completed ? 'line-through text-gray-400' : 'text-gray-800'"
              :aria-expanded="isExpanded(task.id)"
              :aria-controls="`task-detail-${task.id}`"
              @click="toggleExpand(task.id)"
            >
              {{ task.title }}
            </button>

            <!-- Badge de estado -->
            <span
              v-if="taskStatus(task) === 'overdue'"
              class="text-xs text-red-500 font-semibold shrink-0"
            >Vencida</span>
            <span
              v-else-if="taskStatus(task) === 'warning'"
              class="text-xs text-yellow-600 font-semibold shrink-0"
            >Atrasada</span>

            <!-- Progreso de subtareas -->
            <span
              v-if="itemProgress(task)"
              class="text-xs text-gray-400 shrink-0 tabular-nums"
              :title="`${itemProgress(task).done} de ${itemProgress(task).total} pasos completados`"
            >
              {{ itemProgress(task).done }}/{{ itemProgress(task).total }}
            </span>

            <!-- Botón Ver / Ocultar detalles (principal CTA de expansión) -->
            <button
              type="button"
              class="text-xs font-medium text-indigo-500 hover:text-indigo-700 shrink-0 whitespace-nowrap"
              :aria-expanded="isExpanded(task.id)"
              @click="toggleExpand(task.id)"
            >
              {{ isExpanded(task.id) ? '▴ Ocultar' : '▾ Ver detalles' }}
            </button>

            <!-- Acciones de gestión (stop para no propagar) -->
            <div v-if="canManage" class="flex gap-2 shrink-0" @click.stop>
              <button
                v-if="editingTaskId !== task.id"
                type="button"
                class="text-xs text-gray-400 hover:text-indigo-600"
                @click="startEditTask(task)"
              >Editar</button>
              <button
                v-else
                type="button"
                class="text-xs text-gray-400 hover:text-gray-600"
                @click="cancelEditTask"
              >Cancelar</button>
              <button
                v-if="editingTaskId !== task.id"
                type="button"
                class="text-xs text-gray-400 hover:text-red-600"
                @click="destroy(task)"
              >Eliminar</button>
            </div>
          </div>

          <!-- ── Panel expandido ───────────────────────────────────── -->
          <div
            v-if="isExpanded(task.id)"
            :id="`task-detail-${task.id}`"
            class="border-t border-gray-100 px-4 py-4"
          >

            <!-- MODO LECTURA -->
            <template v-if="editingTaskId !== task.id">
              <div class="space-y-3">

                <!-- Descripción -->
                <div v-if="task.description">
                  <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Descripción</p>
                  <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ task.description }}</p>
                </div>

                <!-- Observaciones (tono más secundario) -->
                <div v-if="task.notes">
                  <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Observaciones</p>
                  <p class="text-sm text-gray-500 whitespace-pre-wrap leading-relaxed">{{ task.notes }}</p>
                </div>

                <!-- Fechas -->
                <div v-if="task.optimal_date || task.due_date" class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                  <span v-if="task.optimal_date">
                    <span class="text-xs text-gray-400">Fecha óptima:</span>
                    <span class="ml-1 text-gray-700 font-medium">{{ formatDate(task.optimal_date) }}</span>
                  </span>
                  <span v-if="task.due_date">
                    <span class="text-xs text-gray-400">Fecha límite:</span>
                    <span class="ml-1 text-gray-700 font-medium">{{ formatDate(task.due_date) }}</span>
                  </span>
                </div>

                <!-- Completada por -->
                <div
                  v-if="task.is_completed && (task.completer || task.completed_at)"
                  class="text-xs text-gray-400"
                >
                  Completada
                  <span v-if="task.completer">
                    por <strong class="text-gray-600">{{ task.completer.name }}</strong>
                  </span>
                  <span v-if="task.completed_at">
                    el <strong class="text-gray-600">{{ formatDate(task.completed_at) }}</strong>
                  </span>
                </div>
              </div>

              <!-- ── Sección de subtareas ──────────────────────────── -->
              <div
                v-if="(task.items && task.items.length) || canManage"
                class="mt-4 pt-3 border-t border-gray-100"
              >
                <!-- Header de progreso -->
                <p
                  v-if="task.items && task.items.length"
                  class="text-xs font-semibold text-gray-500 mb-3"
                >
                  Checklist — {{ itemProgress(task).done }} de {{ itemProgress(task).total }} pasos completados
                </p>

                <!-- Lista de subtareas -->
                <div
                  v-if="task.items && task.items.length"
                  class="space-y-1.5 mb-3"
                >
                  <div
                    v-for="item in task.items"
                    :key="item.id"
                    class="flex items-center gap-2 group"
                  >
                    <!-- Checkbox de subtarea -->
                    <div class="shrink-0">
                      <input
                        v-if="canManage"
                        type="checkbox"
                        :checked="item.is_completed"
                        class="rounded border-gray-300 text-indigo-600 cursor-pointer"
                        @change="toggleItem(task, item)"
                      />
                      <span v-else class="text-sm select-none">{{ item.is_completed ? '☑' : '☐' }}</span>
                    </div>

                    <!-- Título o formulario de edición -->
                    <template v-if="editingItemId === item.id">
                      <input
                        v-model="editItemTitle"
                        type="text"
                        class="flex-1 text-sm rounded border-gray-300 focus:border-indigo-400 focus:ring-indigo-400"
                        @keyup.enter="saveEditItem(task, item)"
                        @keyup.escape="cancelEditItem"
                      />
                      <button
                        type="button"
                        class="text-xs text-indigo-600 hover:text-indigo-800 shrink-0 font-medium"
                        @click="saveEditItem(task, item)"
                      >✓</button>
                      <button
                        type="button"
                        class="text-xs text-gray-400 hover:text-gray-600 shrink-0"
                        @click="cancelEditItem"
                      >✕</button>
                    </template>
                    <template v-else>
                      <span
                        class="flex-1 text-sm"
                        :class="item.is_completed ? 'line-through text-gray-400' : 'text-gray-700'"
                      >{{ item.title }}</span>
                      <template v-if="canManage">
                        <button
                          type="button"
                          class="shrink-0 text-gray-300 hover:text-indigo-500 opacity-0 group-hover:opacity-100 transition-opacity"
                          title="Editar paso"
                          @click="startEditItem(item)"
                        >
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                          </svg>
                        </button>
                        <button
                          type="button"
                          class="shrink-0 text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                          title="Eliminar paso"
                          @click="destroyItem(task, item)"
                        >
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </button>
                      </template>
                    </template>
                  </div>
                </div>

                <!-- Formulario agregar subtarea -->
                <form
                  v-if="canManage"
                  @submit.prevent="addItem(task)"
                  class="flex gap-2"
                >
                  <input
                    v-model="newItemTitles[task.id]"
                    type="text"
                    placeholder="Agregar paso..."
                    class="flex-1 text-sm rounded-md border-gray-200 shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                  />
                  <button
                    type="submit"
                    class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md font-medium whitespace-nowrap"
                  >
                    + Agregar
                  </button>
                </form>
              </div>
            </template>

            <!-- MODO EDICIÓN (se activa desde botón Editar en la fila) -->
            <template v-if="editingTaskId === task.id">
              <div class="space-y-3">
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Título</label>
                  <input
                    v-model="editTaskForm.title"
                    type="text"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    @keyup.escape="cancelEditTask"
                  />
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Descripción</label>
                  <textarea
                    v-model="editTaskForm.description"
                    rows="2"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Observaciones</label>
                  <textarea
                    v-model="editTaskForm.notes"
                    rows="2"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Fecha óptima</label>
                    <input
                      v-model="editTaskForm.optimal_date"
                      type="date"
                      class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Fecha límite</label>
                    <input
                      v-model="editTaskForm.due_date"
                      type="date"
                      class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                  </div>
                </div>
                <div class="flex gap-2 pt-1">
                  <button
                    type="button"
                    class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700"
                    @click="saveEditTask(task)"
                  >
                    Guardar cambios
                  </button>
                  <button
                    type="button"
                    class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded text-xs hover:bg-gray-200"
                    @click="cancelEditTask"
                  >
                    Cancelar
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <p v-else class="text-center text-gray-400 py-12 text-sm">
        No hay tareas para esta edición.
      </p>

      <!-- ══════════ SECCIÓN DOCUMENTACIÓN ══════════════════════════════════ -->
      <div class="mt-10">

        <!-- Encabezado de documentación -->
        <div class="mb-4 flex items-center justify-between gap-4">
          <h3 class="font-semibold text-gray-700 shrink-0">Documentación</h3>
          <button
            v-if="canManage"
            type="button"
            class="text-sm text-indigo-600 hover:text-indigo-800 font-medium shrink-0"
            @click="showUploadForm = !showUploadForm"
          >
            {{ showUploadForm ? '✕ Cancelar' : '+ Subir archivo' }}
          </button>
        </div>

        <!-- Formulario de subida -->
        <form
          v-if="canManage && showUploadForm"
          class="mb-5 bg-white rounded-lg shadow-sm border border-gray-100 p-4"
          @submit.prevent="submitUpload"
        >
          <div class="space-y-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Archivo</label>
              <input
                type="file"
                class="w-full text-sm text-gray-700 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                @change="onFileSelected"
              />
              <p v-if="uploadForm.errors.file" class="text-xs text-red-600 mt-1">{{ uploadForm.errors.file }}</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Nombre del documento</label>
              <input
                v-model="uploadForm.name"
                type="text"
                placeholder="Ej: Recorridos, Presupuesto..."
                class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
              <p v-if="uploadForm.errors.name" class="text-xs text-red-600 mt-1">{{ uploadForm.errors.name }}</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Descripción <span class="text-gray-400">(opcional)</span></label>
              <textarea
                v-model="uploadForm.description"
                rows="2"
                placeholder="Contexto o información adicional..."
                class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div class="flex gap-2 pt-1">
              <button
                type="submit"
                :disabled="uploadForm.processing || !uploadForm.file"
                class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {{ uploadForm.processing ? 'Subiendo...' : 'Subir archivo' }}
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm hover:bg-gray-200"
                @click="showUploadForm = false; uploadForm.reset()"
              >
                Cancelar
              </button>
            </div>
          </div>
        </form>

        <!-- Lista de documentos -->
        <div v-if="documents.length" class="space-y-3">
          <div
            v-for="doc in documents"
            :key="doc.id"
            class="bg-white rounded-lg shadow-sm border border-gray-100 p-4"
          >
            <!-- MODO LECTURA -->
            <template v-if="editingDocId !== doc.id">
              <div class="flex items-start gap-3">
                <span class="text-xl shrink-0 leading-none mt-0.5">{{ docIcon(doc.mime_type) }}</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ doc.name }}</p>
                  <p class="text-xs text-gray-400 mt-0.5">
                    {{ doc.file_name }} · {{ formatBytes(doc.file_size) }}
                  </p>
                  <p v-if="doc.description" class="text-sm text-gray-600 mt-2 whitespace-pre-wrap leading-relaxed">
                    {{ doc.description }}
                  </p>
                  <p class="text-xs text-gray-400 mt-2">
                    Subido por <strong class="text-gray-600">{{ doc.uploader?.name ?? '—' }}</strong>
                    el {{ formatDate(doc.created_at) }}
                  </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <a
                    :href="route('teams.documents.download', { team: team, doc: doc.id })"
                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                  >
                    Descargar
                  </a>
                  <template v-if="canManage">
                    <span class="text-gray-200">|</span>
                    <button
                      type="button"
                      class="text-xs text-gray-400 hover:text-indigo-600"
                      @click="startEditDoc(doc)"
                    >
                      Editar
                    </button>
                    <span class="text-gray-200">|</span>
                    <button
                      type="button"
                      class="text-xs text-gray-400 hover:text-red-600"
                      @click="destroyDoc(doc)"
                    >
                      Eliminar
                    </button>
                  </template>
                </div>
              </div>
            </template>

            <!-- MODO EDICIÓN -->
            <template v-else>
              <div class="space-y-3">
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Nombre</label>
                  <input
                    v-model="editDocForm.name"
                    type="text"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    @keyup.escape="cancelEditDoc"
                  />
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Descripción</label>
                  <textarea
                    v-model="editDocForm.description"
                    rows="2"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
                <div class="flex gap-2">
                  <button
                    type="button"
                    class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700"
                    @click="saveEditDoc(doc)"
                  >
                    Guardar
                  </button>
                  <button
                    type="button"
                    class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded text-xs hover:bg-gray-200"
                    @click="cancelEditDoc"
                  >
                    Cancelar
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>

        <p v-else class="text-center text-gray-400 py-8 text-sm">
          No hay documentos para esta edición.
        </p>
      </div>

    </div>
  </AppLayout>
</template>
