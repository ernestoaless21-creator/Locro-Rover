<script setup>
/**
 * Fase P2: importacion historica de clientes/pedidos desde Excel.
 *
 * Flujo obligatorio en 2 pasos contra el backend (nunca se puede importar
 * sin haber analizado antes, ver HistoricalImportController):
 *   1. analyze()  -> sube el archivo, arma una vista previa (dry-run, no
 *                    escribe nada en la base) y devuelve un token.
 *   2. confirm()  -> con ese token + la edicion elegida + los rovers no
 *                    reconocidos ya resueltos, ejecuta la importacion real
 *                    (todo o nada, ver ImportService).
 *
 * axios directo (no router.post de Inertia): hay upload de archivo + un paso
 * intermedio de revision antes de decidir si se "confirma" nada, mismo
 * patron que ya usan GiftFormModal.vue / Orders/New.vue para llamadas que no
 * son una navegacion.
 */
import { Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  years: { type: Array, required: true },
  users: { type: Array, default: () => [] },
})

const currencyFormatter = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 })
function formatCurrency(value) {
  return currencyFormatter.format(Number(value ?? 0))
}

// ---------- Paso 1/2: edicion + archivo -----------------------------------

const activeYear = props.years.find((y) => y.is_active) ?? props.years[0] ?? null
const selectedYearId = ref(activeYear?.id ?? '')
const fileInput = ref(null)
const selectedFile = ref(null)

function onFileChange(event) {
  selectedFile.value = event.target.files[0] ?? null
}

// ---------- Analizar --------------------------------------------------------

const analyzing = ref(false)
const analyzeErrorMessage = ref(null)
const needsFormatSelection = ref(false)
const formatCandidates = ref([])
const selectedFormat = ref(null)

const token = ref(null)
const preview = ref(null)
const roverOverrides = ref({})

async function analyze() {
  if (!selectedFile.value) return

  analyzing.value = true
  analyzeErrorMessage.value = null
  needsFormatSelection.value = false

  const formData = new FormData()
  formData.append('file', selectedFile.value)
  if (selectedFormat.value) {
    formData.append('format', selectedFormat.value)
  }

  try {
    const { data } = await axios.post(route('imports.analyze'), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    token.value = data.token
    preview.value = data.preview
    roverOverrides.value = Object.fromEntries((data.preview.unresolved_rovers ?? []).map((name) => [name, '']))
  } catch (e) {
    const body = e.response?.data
    if (body?.needs_format_selection) {
      needsFormatSelection.value = true
      formatCandidates.value = body.candidates ?? []
      analyzeErrorMessage.value = body.message
    } else {
      analyzeErrorMessage.value = body?.message ?? 'No se pudo analizar el archivo. Intentá de nuevo.'
    }
  } finally {
    analyzing.value = false
  }
}

function retryWithFormat(formatValue) {
  selectedFormat.value = formatValue
  analyze()
}

// ---------- Confirmar --------------------------------------------------------

const confirming = ref(false)
const confirmErrorMessage = ref(null)
const result = ref(null)

const canConfirm = computed(() => !!preview.value?.can_import && !!selectedYearId.value && !confirming.value)

async function confirmImport() {
  if (!canConfirm.value) return

  confirming.value = true
  confirmErrorMessage.value = null

  const overridesPayload = Object.fromEntries(
    Object.entries(roverOverrides.value).map(([name, userId]) => [name, userId ? Number(userId) : null]),
  )

  try {
    const { data } = await axios.post(route('imports.confirm'), {
      token: token.value,
      year_id: selectedYearId.value,
      format: preview.value.format,
      rover_overrides: overridesPayload,
    })
    result.value = data.result
  } catch (e) {
    confirmErrorMessage.value = e.response?.data?.message ?? 'No se pudo completar la importación. Intentá de nuevo.'
  } finally {
    confirming.value = false
  }
}

function startOver() {
  if (token.value) {
    axios.delete(route('imports.cancel', token.value)).catch(() => {})
  }
  selectedFile.value = null
  if (fileInput.value) fileInput.value.value = ''
  analyzeErrorMessage.value = null
  needsFormatSelection.value = false
  formatCandidates.value = []
  selectedFormat.value = null
  token.value = null
  preview.value = null
  roverOverrides.value = {}
  confirmErrorMessage.value = null
  result.value = null
}

const rowStatusLabel = { ok: 'OK', warning: 'Advertencia', error: 'Error' }
const rowStatusClass = {
  ok: 'text-green-400',
  warning: 'text-amber-400',
  error: 'text-red-400',
}
</script>

<template>
  <Head title="Importaciones" />

  <AppLayout title="Importaciones">
    <template #header>
      <h2 class="font-semibold text-xl text-white leading-tight">
        Importar Excel
      </h2>
    </template>

    <div class="py-6 max-w-5xl mx-auto px-4 space-y-5">
      <p class="text-sm text-gray-400">
        Importa clientes y pedidos históricos desde un archivo Excel (.xlsx). El teléfono se usa
        como identificador principal: si el cliente ya existe, se reutiliza; si no, se crea. El
        archivo se analiza primero (sin tocar la base) y solo se importa después de confirmar la
        vista previa.
      </p>

      <!-- Resultado final: reemplaza todo lo demás -->
      <div v-if="result" class="bg-gray-900 border border-green-700 rounded-lg p-5 space-y-4">
        <h3 class="font-semibold text-white text-lg">Importación completada</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Clientes creados</div>
            <div class="text-xl font-semibold text-white">{{ result.clients_created }}</div>
          </div>
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Clientes reutilizados</div>
            <div class="text-xl font-semibold text-white">{{ result.clients_reused }}</div>
          </div>
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Pedidos creados</div>
            <div class="text-xl font-semibold text-white">{{ result.orders_created }}</div>
          </div>
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Porciones importadas</div>
            <div class="text-xl font-semibold text-white">{{ result.portions_imported }}</div>
          </div>
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Importe total</div>
            <div class="text-xl font-semibold text-white">{{ formatCurrency(result.total_amount) }}</div>
          </div>
          <div class="bg-gray-800 rounded-md p-3">
            <div class="text-xs text-gray-400">Tiempo empleado</div>
            <div class="text-xl font-semibold text-white">{{ (result.elapsed_ms / 1000).toFixed(1) }}s</div>
          </div>
        </div>
        <button
          type="button"
          class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold"
          @click="startOver"
        >
          Nueva importación
        </button>
      </div>

      <template v-else>
        <!-- Paso 1: edicion + archivo -->
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-5 space-y-4">
          <h3 class="font-semibold text-white mb-1">1. Elegí la edición y el archivo</h3>

          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm text-gray-400 mb-1">Edición destino</label>
              <select
                v-model="selectedYearId"
                :disabled="!!preview"
                class="w-full bg-gray-800 border-gray-700 text-white rounded-md shadow-sm text-sm focus:border-red-600 focus:ring-red-600 disabled:opacity-50"
              >
                <option value="" disabled>Seleccionar edición...</option>
                <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label || y.year }}</option>
              </select>
            </div>

            <div>
              <label class="block text-sm text-gray-400 mb-1">Archivo (.xlsx)</label>
              <input
                ref="fileInput"
                type="file"
                accept=".xlsx"
                :disabled="!!preview"
                class="w-full text-sm text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600 disabled:opacity-50"
                @change="onFileChange"
              >
            </div>
          </div>

          <p v-if="analyzeErrorMessage" class="text-sm text-red-400">{{ analyzeErrorMessage }}</p>

          <div v-if="needsFormatSelection" class="bg-amber-950/40 border border-amber-700 rounded-md p-3 space-y-2">
            <p class="text-sm text-amber-300">
              El archivo coincide con más de un formato posible. Elegí cuál usar:
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="c in formatCandidates"
                :key="c.value"
                type="button"
                class="px-3 py-1.5 rounded-md text-sm bg-gray-800 text-white hover:bg-gray-700 border border-gray-600"
                @click="retryWithFormat(c.value)"
              >
                {{ c.label }}
              </button>
            </div>
          </div>

          <button
            v-if="!preview"
            type="button"
            :disabled="!selectedYearId || !selectedFile || analyzing"
            class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
            @click="analyze"
          >
            {{ analyzing ? 'Analizando…' : 'Analizar archivo' }}
          </button>
        </div>

        <!-- Paso 2: vista previa -->
        <div v-if="preview" class="bg-gray-900 border border-gray-700 rounded-lg p-5 space-y-4">
          <h3 class="font-semibold text-white mb-1">
            2. Vista previa
            <span class="text-gray-400 font-normal text-sm">— formato detectado: {{ preview.format_label }}</span>
          </h3>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Clientes encontrados</div>
              <div class="text-lg font-semibold text-white">{{ preview.existing_clients_matched }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Clientes a crear</div>
              <div class="text-lg font-semibold text-white">{{ preview.new_clients_to_create }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Pedidos</div>
              <div class="text-lg font-semibold text-white">{{ preview.orders_to_create }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Porciones totales</div>
              <div class="text-lg font-semibold text-white">{{ preview.total_portions }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Importe total</div>
              <div class="text-lg font-semibold text-white">{{ formatCurrency(preview.total_amount) }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3" :class="preview.error_rows > 0 ? 'ring-1 ring-red-600' : ''">
              <div class="text-xs text-gray-400">Filas con errores</div>
              <div class="text-lg font-semibold" :class="preview.error_rows > 0 ? 'text-red-400' : 'text-white'">{{ preview.error_rows }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Filas duplicadas</div>
              <div class="text-lg font-semibold text-white">{{ preview.duplicate_rows }}</div>
            </div>
            <div class="bg-gray-800 rounded-md p-3">
              <div class="text-xs text-gray-400">Teléfonos inválidos</div>
              <div class="text-lg font-semibold text-white">{{ preview.invalid_phone_rows }}</div>
            </div>
          </div>

          <!-- Errores bloqueantes -->
          <div v-if="!preview.can_import" class="bg-red-950/40 border border-red-700 rounded-md p-3 space-y-1">
            <p class="text-sm text-red-300 font-medium">
              El archivo tiene errores que impiden la importación. Corregilo y volvé a subirlo.
            </p>
            <ul class="text-xs text-red-300 list-disc list-inside max-h-40 overflow-y-auto">
              <li v-for="(e, idx) in preview.errors" :key="idx">Fila {{ e.row }}: {{ e.message }}</li>
            </ul>
          </div>

          <!-- Rovers no reconocidos -->
          <div v-if="preview.unresolved_rovers?.length" class="bg-amber-950/40 border border-amber-700 rounded-md p-3 space-y-2">
            <p class="text-sm text-amber-300 font-medium">
              No se reconoció a estos Rovers por nombre. Elegí a quién asignar cada uno (o dejalo sin asignar):
            </p>
            <div v-for="name in preview.unresolved_rovers" :key="name" class="flex items-center gap-3">
              <span class="text-sm text-gray-300 w-48 truncate" :title="name">{{ name }}</span>
              <select
                v-model="roverOverrides[name]"
                class="bg-gray-800 border-gray-700 text-white rounded-md shadow-sm text-sm focus:border-red-600 focus:ring-red-600"
              >
                <option value="">Sin asignar</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </div>
          </div>

          <!-- Muestra de filas -->
          <div v-if="preview.sample_rows?.length" class="overflow-x-auto rounded-md border border-gray-700">
            <table class="w-full text-sm bg-gray-900 text-white">
              <thead class="bg-gray-800">
                <tr>
                  <th class="p-2 text-left">Fila</th>
                  <th class="p-2 text-left">Nombre</th>
                  <th class="p-2 text-left">Teléfono</th>
                  <th class="p-2 text-left">Porciones</th>
                  <th class="p-2 text-left">Importe</th>
                  <th class="p-2 text-left">Estado</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in preview.sample_rows" :key="r.row" class="border-t border-gray-800">
                  <td class="p-2 text-gray-400">{{ r.row }}</td>
                  <td class="p-2">{{ r.name || '—' }}</td>
                  <td class="p-2 text-gray-400">{{ r.phone || '—' }}</td>
                  <td class="p-2 text-gray-400">{{ r.portions ?? '—' }}</td>
                  <td class="p-2 text-gray-400">{{ r.total_amount != null ? formatCurrency(r.total_amount) : '—' }}</td>
                  <td class="p-2 font-medium" :class="rowStatusClass[r.status]">{{ rowStatusLabel[r.status] }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="preview.total_rows > preview.sample_rows.length" class="text-xs text-gray-500 p-2">
              Mostrando {{ preview.sample_rows.length }} de {{ preview.total_rows }} filas.
            </p>
          </div>
        </div>

        <!-- Paso 3: confirmar -->
        <div v-if="preview" class="bg-gray-900 border border-gray-700 rounded-lg p-5 space-y-3">
          <h3 class="font-semibold text-white mb-1">3. Confirmar importación</h3>
          <p v-if="confirmErrorMessage" class="text-sm text-red-400">{{ confirmErrorMessage }}</p>
          <div class="flex gap-3">
            <button
              type="button"
              :disabled="!canConfirm"
              class="bg-red-700 hover:bg-red-600 text-white px-5 py-2 rounded-md text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
              @click="confirmImport"
            >
              {{ confirming ? 'Importando…' : 'Confirmar importación' }}
            </button>
            <button
              type="button"
              class="bg-gray-800 hover:bg-gray-700 text-white px-5 py-2 rounded-md text-sm font-semibold"
              :disabled="confirming"
              @click="startOver"
            >
              Cancelar
            </button>
          </div>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
