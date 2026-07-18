<script setup>
import { ref, computed } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import HistoricalEditionBanner from '@/Components/HistoricalEditionBanner.vue'
import { useToast } from '@/Composables/useToast'
import { useEditableYear } from '@/Composables/useEditableYear'

const props = defineProps({
  team:       { type: String,  required: true },
  year:       { type: Object,  required: true },
  materials:  { type: Array,   required: true },
  categories: { type: Array,   required: true },
  canManage:  { type: Boolean, required: true },
})

const toast = useToast()
const canMutateYear = useEditableYear(() => props.year)
const canManageNow = computed(() => props.canManage && canMutateYear.value)

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

// Ícono + etiqueta a partir de la extensión del archivo (alcanza con
// detectarla, no hace falta una lista infinita de tipos).
const FILE_TYPE_ICONS = {
  PDF: '📄',
  JPG: '🖼', JPEG: '🖼', PNG: '🖼', GIF: '🖼', WEBP: '🖼', SVG: '🖼',
  MP4: '🎥', MOV: '🎥', AVI: '🎥', WEBM: '🎥',
  MP3: '🎵', WAV: '🎵',
  ZIP: '📦', RAR: '📦',
  DOC: '📝', DOCX: '📝',
  PPT: '📊', PPTX: '📊',
  XLS: '📊', XLSX: '📊',
}

function fileType(fileName) {
  const label = (fileName || '').split('.').pop()?.toUpperCase() || '—'
  return { icon: FILE_TYPE_ICONS[label] ?? '📎', label }
}

function fmtDate(str) {
  if (!str) return null
  const [y, m, d] = String(str).split('T')[0].split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' })
}

// ─── Filtros ────────────────────────────────────────────────────────────────
const activeCategoryId = ref(null) // null = todas
const search = ref('')

const filteredMaterials = computed(() => {
  let list = props.materials
  if (activeCategoryId.value !== null) {
    list = list.filter((m) => m.publicity_category_id === activeCategoryId.value)
  }
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase()
    list = list.filter((m) =>
      m.title.toLowerCase().includes(q) || (m.description ?? '').toLowerCase().includes(q)
    )
  }
  return list
})

// ─── Subir material ─────────────────────────────────────────────────────────
const showUploadForm = ref(false)
const uploadForm = useForm({
  file: null, publicity_category_id: '', title: '', description: '', notes: '', material_date: '', year_id: props.year.id,
})

function onFileSelected(e) {
  uploadForm.file = e.target.files[0] ?? null
  if (uploadForm.file && !uploadForm.title.trim()) {
    uploadForm.title = uploadForm.file.name.replace(/\.[^.]+$/, '')
  }
}

function submitUpload() {
  uploadForm.year_id = props.year.id
  uploadForm.post(route('publicity.store', props.team), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { uploadForm.reset(); showUploadForm.value = false },
    onError: () => toast.error('Error al subir el material.'),
  })
}

// ─── Nueva categoría (quick-add) ────────────────────────────────────────────
const showNewCategory = ref(false)
const newCategoryForm = useForm({ name: '' })

function submitNewCategory() {
  if (!newCategoryForm.name.trim()) return
  newCategoryForm.post(route('publicity.categories.store', props.team), {
    preserveScroll: true,
    onSuccess: () => { showNewCategory.value = false; newCategoryForm.reset() },
    onError: () => toast.error('Error al crear la categoría.'),
  })
}

// ─── Editar material ────────────────────────────────────────────────────────
const editingId = ref(null)
const editForm = useForm({ publicity_category_id: '', title: '', description: '', notes: '', material_date: '', file: null })

function startEdit(m) {
  editingId.value = m.id
  editForm.clearErrors()
  editForm.publicity_category_id = m.publicity_category_id
  editForm.title = m.title
  editForm.description = m.description ?? ''
  editForm.notes = m.notes ?? ''
  editForm.material_date = m.material_date ? String(m.material_date).split('T')[0] : ''
  editForm.file = null
}
function cancelEdit() {
  editingId.value = null
}
function onEditFileSelected(e) {
  editForm.file = e.target.files[0] ?? null
}
function saveEdit(m) {
  if (!editForm.title.trim() || !editForm.publicity_category_id) return
  editForm.put(route('publicity.update', { team: props.team, material: m.id }), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => cancelEdit(),
    onError: () => toast.error('Error al actualizar el material.'),
  })
}

function destroyMaterial(m) {
  if (!window.confirm(`¿Eliminar "${m.title}"? Esta acción no se puede deshacer.`)) return
  router.delete(route('publicity.destroy', { team: props.team, material: m.id }), {
    preserveScroll: true,
    onSuccess: () => toast.success('Material eliminado.'),
  })
}
</script>

<template>
  <Head :title="`Publicidad — Edición ${year.year}`" />
  <AppLayout :title="`Publicidad — Edición ${year.year}`">
    <template #header>
      <div class="flex items-center gap-4">
        <a :href="route('teams.show', team)" class="text-xs text-ember hover:text-ember-strong uppercase tracking-wide">
          ← Publicidad
        </a>
        <h2 class="font-semibold text-xl text-white leading-tight">Publicidad histórica</h2>
      </div>
    </template>

    <div class="py-8 max-w-3xl mx-auto px-4">

      <!-- Año + navegación -->
      <div class="mb-6 space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-3">
          <YearSelector :selected-year-id="year.id" />
          <a v-if="canManage" :href="route('publicity.import', { team, target_year_id: year.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
            Importar desde otra edición
          </a>
        </div>
        <HistoricalEditionBanner :year="year" />
      </div>

      <!-- Filtros -->
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <button type="button" class="px-3 py-1 rounded-full text-xs font-medium"
          :class="activeCategoryId === null ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="activeCategoryId = null">Todas</button>
        <button v-for="c in categories" :key="c.id" type="button" class="px-3 py-1 rounded-full text-xs font-medium"
          :class="activeCategoryId === c.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="activeCategoryId = c.id">{{ c.name }}</button>
        <input v-model="search" type="text" placeholder="Buscar por título o descripción..."
          class="ml-auto w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
      </div>

      <!-- Subir material -->
      <div v-if="canManageNow" class="mb-6">
        <button v-if="!showUploadForm" type="button" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium" @click="showUploadForm = true">
          + Subir material
        </button>

        <!-- NO es <form>: evita que Enter en "Nueva categoría" dispare el submit antes de tiempo. -->
        <div v-else class="mt-2 bg-white rounded-lg shadow-sm border border-gray-100 p-4 space-y-3">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Archivo *</label>
            <input type="file"
              class="w-full text-sm text-gray-700 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
              @change="onFileSelected" />
            <p v-if="uploadForm.errors.file" class="text-xs text-red-600 mt-1">{{ uploadForm.errors.file }}</p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Título *</label>
              <input v-model="uploadForm.title" type="text" placeholder="Ej: Flyer principal"
                class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                @keydown.enter.prevent />
              <p v-if="uploadForm.errors.title" class="text-xs text-red-600 mt-1">{{ uploadForm.errors.title }}</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Categoría *</label>
              <div class="flex items-center gap-2">
                <select v-model="uploadForm.publicity_category_id"
                  class="flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                  <option value="">Seleccionar…</option>
                  <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap" @click="showNewCategory = !showNewCategory">
                  + categoría
                </button>
              </div>
              <p v-if="uploadForm.errors.publicity_category_id" class="text-xs text-red-600 mt-1">{{ uploadForm.errors.publicity_category_id }}</p>
            </div>
          </div>

          <div v-if="showNewCategory" class="flex gap-2 items-end bg-gray-50 rounded-lg p-2">
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">Nueva categoría</label>
              <input v-model="newCategoryForm.name" type="text" placeholder="Ej: Stories"
                class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                @keydown.enter.prevent="submitNewCategory" />
              <p v-if="newCategoryForm.errors.name" class="text-xs text-red-600 mt-1">{{ newCategoryForm.errors.name }}</p>
            </div>
            <button type="button" class="px-3 py-1.5 bg-green-600 text-white text-xs rounded-lg hover:bg-green-500" @click="submitNewCategory">
              Crear
            </button>
          </div>

          <div>
            <label class="block text-xs text-gray-500 mb-1">Descripción (opcional)</label>
            <textarea v-model="uploadForm.description" rows="2" placeholder="Contexto o información adicional..."
              class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Observaciones (opcional)</label>
              <input v-model="uploadForm.notes" type="text" placeholder="Notas internas"
                class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                @keydown.enter.prevent />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Fecha (opcional)</label>
              <input v-model="uploadForm.material_date" type="date"
                class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
            </div>
          </div>

          <div class="flex gap-2 pt-1">
            <button type="button" :disabled="uploadForm.processing || !uploadForm.file" @click="submitUpload"
              class="px-4 py-2 bg-green-600 text-white rounded-md text-sm hover:bg-green-500 disabled:opacity-40 disabled:cursor-not-allowed">
              {{ uploadForm.processing ? 'Subiendo...' : 'Subir material' }}
            </button>
            <button type="button" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm hover:bg-gray-200"
              @click="showUploadForm = false; uploadForm.reset()">
              Cancelar
            </button>
          </div>
        </div>
      </div>

      <!-- Lista de materiales -->
      <div v-if="filteredMaterials.length" class="space-y-3">
        <div v-for="m in filteredMaterials" :key="m.id" class="bg-white rounded-lg shadow-sm border border-gray-100 p-4">

          <template v-if="editingId !== m.id">
            <div class="flex items-start gap-3">
              <span class="text-xl shrink-0 leading-none mt-0.5">{{ fileType(m.file_name).icon }}</span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <p class="text-sm font-medium text-gray-800">{{ m.title }}</p>
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-600">
                    {{ m.category.name }}
                  </span>
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                    {{ fileType(m.file_name).icon }} {{ fileType(m.file_name).label }}
                  </span>
                </div>
                <p class="text-xs text-gray-400 mt-0.5" :title="m.file_name">
                  {{ fileType(m.file_name).label }} · {{ formatBytes(m.file_size) }}
                  <template v-if="m.material_date"> · {{ fmtDate(m.material_date) }}</template>
                </p>
                <p v-if="m.description" class="text-sm text-gray-600 mt-2 whitespace-pre-wrap leading-relaxed">{{ m.description }}</p>
                <p v-if="m.notes" class="text-xs text-amber-700 italic mt-2 border-l-2 border-amber-200 pl-2">{{ m.notes }}</p>
                <p class="text-xs text-gray-400 mt-2">
                  Subido por <strong class="text-gray-600">{{ m.uploader?.name ?? '—' }}</strong> el {{ fmtDate(m.created_at) }}
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <a :href="route('publicity.view', { team, material: m.id })" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                  Ver
                </a>
                <span class="text-gray-200">|</span>
                <a :href="route('publicity.download', { team, material: m.id })" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                  Descargar
                </a>
                <template v-if="canManageNow">
                  <span class="text-gray-200">|</span>
                  <button type="button" class="text-xs text-gray-400 hover:text-indigo-600" @click="startEdit(m)">Editar</button>
                  <span class="text-gray-200">|</span>
                  <button type="button" class="text-xs text-gray-400 hover:text-red-600" @click="destroyMaterial(m)">Eliminar</button>
                </template>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Título *</label>
                  <input v-model="editForm.title" type="text" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Categoría *</label>
                  <select v-model="editForm.publicity_category_id" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="block text-xs text-gray-500 mb-1">Descripción</label>
                <textarea v-model="editForm.description" rows="2" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Observaciones</label>
                  <input v-model="editForm.notes" type="text" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Fecha</label>
                  <input v-model="editForm.material_date" type="date" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
              </div>
              <div class="border-t border-gray-100 pt-3">
                <label class="block text-xs text-gray-500 mb-1">Reemplazar archivo (opcional)</label>
                <input type="file"
                  class="w-full text-sm text-gray-700 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                  @change="onEditFileSelected" />
                <p class="text-xs text-gray-400 mt-1">
                  Archivo actual: {{ m.file_name }}. Si no elegís uno nuevo, se conserva el que ya está.
                </p>
                <p v-if="editForm.errors.file" class="text-xs text-red-600 mt-1">{{ editForm.errors.file }}</p>
              </div>
              <div class="flex gap-2">
                <button type="button" class="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-500" @click="saveEdit(m)">
                  Guardar
                </button>
                <button type="button" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded text-xs hover:bg-gray-200" @click="cancelEdit">
                  Cancelar
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div v-else class="text-center text-gray-400 py-12 text-sm">
        <template v-if="materials.length === 0">
          <p class="text-2xl mb-1">📣</p>
          <p>Todavía no hay material publicitario para esta edición.</p>
        </template>
        <template v-else>
          <p class="text-2xl mb-1">🔎</p>
          <p>No se encontró material con ese filtro.</p>
        </template>
      </div>

    </div>
  </AppLayout>
</template>
