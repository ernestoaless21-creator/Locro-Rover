<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  years:           { type: Array,  required: true },
  targetYear:      { type: Object, required: true },
  teams:           { type: Array,  required: true },
  sourceData:      { type: Object, default: null },
  preselectedTeam: { type: String, default: null },
})

const TEAM_LABELS = {
  logistica:       'Logística',
  compras:         'Compras',
  infraestructura: 'Infraestructura',
  publicidad:      'Publicidad',
}

// ---------- Selector de año fuente ----------------------------------------

const selectedSourceYearId = ref(props.sourceData?.source_year_id ?? '')

function previewSource() {
  if (!selectedSourceYearId.value) return
  const params = {
    source_year_id: selectedSourceYearId.value,
    target_year_id: props.targetYear.id,
  }
  if (props.preselectedTeam) {
    params.team = props.preselectedTeam
  }
  router.get(route('teams.import'), params, { preserveState: false })
}

// ---------- Teams disponibles en origen ------------------------------------

const availableTeams = computed(() => {
  if (!props.sourceData) return []
  return props.teams.filter((t) => (props.sourceData.source_counts[t]?.tasks ?? 0) > 0)
})

// ---------- Selección de equipos ------------------------------------------

const selectedTeams = ref(
  (() => {
    if (!props.sourceData) return []
    // Si venimos desde un equipo específico, preseleccionar solo ese
    if (props.preselectedTeam && availableTeams.value.includes(props.preselectedTeam)) {
      return [props.preselectedTeam]
    }
    // Por defecto: seleccionar todos los equipos disponibles
    return [...availableTeams.value]
  })(),
)

function toggleTeam(team) {
  const idx = selectedTeams.value.indexOf(team)
  if (idx === -1) {
    selectedTeams.value.push(team)
  } else {
    selectedTeams.value.splice(idx, 1)
    // Si se deselecciona el equipo, limpiar su resolución de conflicto
    delete conflictResolutions.value[team]
  }
}

const selectAll = computed({
  get() {
    return (
      availableTeams.value.length > 0 &&
      availableTeams.value.every((t) => selectedTeams.value.includes(t))
    )
  },
  set(val) {
    if (!val) {
      // Al deseleccionar todos, limpiar resoluciones
      for (const t of selectedTeams.value) {
        delete conflictResolutions.value[t]
      }
    }
    selectedTeams.value = val ? [...availableTeams.value] : []
  },
})

// ---------- Resolución de conflictos --------------------------------------

const conflictResolutions = ref({})

function hasConflict(team) {
  return !!(props.sourceData?.conflicts?.[team])
}

function resolutionFor(team) {
  return conflictResolutions.value[team] ?? null
}

function setResolution(team, val) {
  conflictResolutions.value[team] = val
}

// ---------- Submit --------------------------------------------------------

const submitting = ref(false)

function submit() {
  if (!canSubmit.value) return
  submitting.value = true

  const resolutions = {}
  for (const team of selectedTeams.value) {
    if (hasConflict(team)) {
      resolutions[team] = resolutionFor(team)
    }
  }

  router.post(
    route('teams.import.store'),
    {
      source_year_id:       selectedSourceYearId.value,
      target_year_id:       props.targetYear.id,
      teams:                selectedTeams.value,
      conflict_resolutions: resolutions,
    },
    {
      onFinish: () => { submitting.value = false },
    },
  )
}

// ---------- Computed helpers ----------------------------------------------

const teamsWithConflictSelected = computed(() =>
  selectedTeams.value.filter((t) => hasConflict(t)),
)

const unresolved = computed(() =>
  teamsWithConflictSelected.value.filter((t) => !conflictResolutions.value[t]),
)

const canSubmit = computed(
  () =>
    !!selectedSourceYearId.value &&
    selectedTeams.value.length > 0 &&
    unresolved.value.length === 0 &&
    !submitting.value,
)

const sourceYears = computed(() =>
  props.years.filter((y) => y.id !== props.targetYear.id),
)

const cancelHref = computed(() =>
  props.preselectedTeam
    ? route('teams.show', props.preselectedTeam)
    : route('teams.show', props.teams[0]),
)
</script>

<template>
  <Head title="Importar tareas" />
  <AppLayout title="Importar tareas">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Importar tareas — Edición {{ targetYear.year }}
        <span v-if="preselectedTeam" class="text-gray-400 font-normal text-lg">
          · {{ TEAM_LABELS[preselectedTeam] ?? preselectedTeam }}
        </span>
      </h2>
    </template>

    <div class="py-8 max-w-2xl mx-auto px-4">

      <!-- Paso 1: elegir año fuente -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-5 mb-6">
        <h3 class="font-semibold text-gray-700 mb-1">1. Elegir edición de origen</h3>
        <p class="text-sm text-gray-500 mb-4">
          Las tareas y pasos se copiarán hacia la edición
          <strong>{{ targetYear.label || targetYear.year }}</strong>.
          El progreso (tareas completadas, fechas, etc.) no se importa.
        </p>
        <div class="flex gap-2">
          <select
            v-model="selectedSourceYearId"
            class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
          >
            <option value="" disabled>Seleccionar edición...</option>
            <option v-for="y in sourceYears" :key="y.id" :value="y.id">
              {{ y.label || y.year }}
            </option>
          </select>
          <button
            type="button"
            :disabled="!selectedSourceYearId"
            class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed"
            @click="previewSource"
          >
            Ver tareas disponibles
          </button>
        </div>
      </div>

      <!-- Paso 2: elegir equipos (solo si sourceData cargó) -->
      <template v-if="sourceData">
        <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-5 mb-6">
          <h3 class="font-semibold text-gray-700 mb-1">2. Elegir equipos a importar</h3>
          <p class="text-sm text-gray-500 mb-4">
            Se muestran todos los equipos con tareas en la edición de origen.
          </p>

          <!-- Sin tareas en origen -->
          <p
            v-if="availableTeams.length === 0"
            class="text-sm text-gray-500 italic"
          >
            La edición seleccionada todavía no tiene tareas registradas.
          </p>

          <template v-else>
            <!-- Seleccionar todos (solo si hay más de un equipo disponible) -->
            <label
              v-if="availableTeams.length > 1"
              class="flex items-center gap-2 mb-3 cursor-pointer select-none"
            >
              <input
                v-model="selectAll"
                type="checkbox"
                class="rounded border-gray-300 text-indigo-600"
              />
              <span class="text-sm font-medium text-gray-700">Seleccionar todos</span>
            </label>

            <div class="space-y-3">
              <div
                v-for="team in teams"
                :key="team"
                class="rounded-lg border"
                :class="
                  availableTeams.includes(team)
                    ? 'border-gray-200'
                    : 'border-gray-100 opacity-30 pointer-events-none'
                "
              >
                <!-- Fila del equipo -->
                <label class="flex items-start gap-3 cursor-pointer select-none p-3">
                  <input
                    :checked="selectedTeams.includes(team)"
                    type="checkbox"
                    :disabled="!availableTeams.includes(team)"
                    class="mt-0.5 rounded border-gray-300 text-indigo-600"
                    @change="toggleTeam(team)"
                  />
                  <div class="flex-1 min-w-0">
                    <span class="font-medium text-sm text-gray-800">{{ TEAM_LABELS[team] ?? team }}</span>

                    <div class="flex flex-wrap gap-x-4 mt-1 text-xs text-gray-500">
                      <span>
                        Disponibles:
                        <strong>{{ sourceData.source_counts[team]?.tasks ?? 0 }} tareas</strong>,
                        {{ sourceData.source_counts[team]?.items ?? 0 }} pasos
                      </span>
                      <span
                        v-if="sourceData.target_counts[team]?.tasks > 0"
                        class="text-amber-600 font-medium"
                      >
                        Ya existen {{ sourceData.target_counts[team].tasks }} tarea(s) en esta edición
                      </span>
                    </div>
                  </div>
                </label>

                <!-- Resolución de conflicto: aparece cuando el equipo está seleccionado y hay conflicto -->
                <div
                  v-if="selectedTeams.includes(team) && hasConflict(team)"
                  class="mx-3 mb-3 pl-3 border-l-2 border-amber-300 bg-amber-50 rounded-r p-3"
                >
                  <p class="text-xs font-semibold text-amber-800 mb-3">
                    Ya existen {{ sourceData.target_counts[team].tasks }} tarea(s) en la edición destino.
                    ¿Qué querés hacer?
                  </p>
                  <div class="space-y-2">
                    <label class="flex items-start gap-2 cursor-pointer">
                      <input
                        :checked="resolutionFor(team) === 'replace'"
                        type="radio"
                        :name="`conflict_${team}`"
                        value="replace"
                        class="mt-0.5 text-red-600"
                        @change="setResolution(team, 'replace')"
                      />
                      <div>
                        <span class="text-sm text-gray-800 font-medium">Reemplazar tareas existentes</span>
                        <p class="text-xs text-gray-500 mt-0.5">
                          Las tareas actuales de este equipo serán eliminadas e importadas desde la edición de origen.
                        </p>
                      </div>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                      <input
                        :checked="resolutionFor(team) === 'skip'"
                        type="radio"
                        :name="`conflict_${team}`"
                        value="skip"
                        class="mt-0.5 text-indigo-600"
                        @change="setResolution(team, 'skip')"
                      />
                      <div>
                        <span class="text-sm text-gray-800 font-medium">Conservar las existentes y omitir la importación</span>
                        <p class="text-xs text-gray-500 mt-0.5">
                          Las tareas actuales se mantienen sin cambios. No se importa nada para este equipo.
                        </p>
                      </div>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Paso 3: confirmar -->
        <div
          v-if="availableTeams.length > 0"
          class="bg-white rounded-lg shadow-sm border border-gray-100 p-5"
        >
          <h3 class="font-semibold text-gray-700 mb-3">3. Confirmar importación</h3>

          <p v-if="selectedTeams.length === 0" class="text-sm text-gray-400 mb-4">
            Seleccioná al menos un equipo para continuar.
          </p>
          <div v-else class="text-sm text-gray-600 mb-4 space-y-2">
            <p>
              Se importarán las tareas de
              <strong>{{ selectedTeams.map((t) => TEAM_LABELS[t] ?? t).join(', ') }}</strong>
              a la edición <strong>{{ targetYear.label || targetYear.year }}</strong>.
            </p>
            <p v-if="unresolved.length > 0" class="text-amber-700 font-medium">
              Hay {{ unresolved.length }} equipo(s) con conflictos sin resolver.
              Elegí una acción para cada uno antes de continuar.
            </p>
          </div>

          <div class="flex gap-3">
            <button
              type="button"
              :disabled="!canSubmit"
              class="px-5 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed"
              @click="submit"
            >
              {{ submitting ? 'Importando...' : 'Importar tareas' }}
            </button>
            <a
              :href="cancelHref"
              class="px-5 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-200"
            >
              Cancelar
            </a>
          </div>
        </div>
      </template>

    </div>
  </AppLayout>
</template>
