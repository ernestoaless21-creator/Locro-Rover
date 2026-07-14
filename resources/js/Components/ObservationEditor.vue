<script setup>
/**
 * Editor inline de observaciones. Corrige el bug de la app anterior:
 * antes Enter no guardaba ni cerraba (habia que hacer click afuera).
 *
 * Comportamiento implementado:
 * - Enter (sin Shift): guarda y cierra la edicion.
 * - Escape: cancela sin guardar y cierra.
 * - Shift+Enter: inserta un salto de linea (no guarda).
 * - Click afuera (blur): guarda automaticamente si hay texto nuevo.
 * - Anti-duplicado: un unico client_request_id (uuid) se genera al ABRIR la
 *   edicion y se reutiliza para todos los intentos de guardado de esa sesion
 *   de edicion. Un flag local 'saved' evita ademas disparar el mismo POST dos
 *   veces si Enter y blur ocurren casi simultaneamente (Enter dispara primero,
 *   pone saved=true, y el blur posterior lo ignora).
 */
import { ref, nextTick } from 'vue'
import axios from 'axios'

const props = defineProps({
  clientId: { type: Number, required: true },
  yearId: { type: Number, required: true },
  observations: { type: Array, default: () => [] }, // historial existente (mas reciente primero)
})

const emit = defineEmits(['saved'])

const editing = ref(false)
const text = ref('')
const saving = ref(false)
const saved = ref(false)
const requestId = ref(null)
const textareaRef = ref(null)
const feedback = ref(null) // 'ok' | 'error' | null

function generateRequestId() {
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function openEditor() {
  editing.value = true
  text.value = ''
  saved.value = false
  requestId.value = generateRequestId()
  await nextTick()
  textareaRef.value?.focus()
}

function cancelEditor() {
  editing.value = false
  text.value = ''
}

async function saveAndClose() {
  if (saved.value || saving.value) return // ya se guardo (o esta guardando) esta sesion de edicion
  if (text.value.trim() === '') {
    editing.value = false
    return
  }

  saved.value = true // se marca ANTES del await para bloquear un segundo disparo simultaneo
  saving.value = true

  try {
    const { data } = await axios.post(`/clients/${props.clientId}/observations`, {
      year_id: props.yearId,
      observation: text.value,
      client_request_id: requestId.value,
    })

    if (data.observation) {
      emit('saved', data.observation)
      feedback.value = 'ok'
    }
  } catch (e) {
    feedback.value = 'error'
    saved.value = false // permite reintentar si fallo
  } finally {
    saving.value = false
    editing.value = false
    setTimeout(() => { feedback.value = null }, 2500)
  }
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault() // evita el salto de linea por defecto del textarea
    saveAndClose()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    cancelEditor()
  }
  // Shift+Enter: no se intercepta, el textarea agrega el salto de linea normalmente.
}

function onBlur() {
  // Guardado automatico al hacer click afuera, salvo que ya se haya guardado
  // (por Enter) o cancelado (por Escape) en este mismo ciclo.
  saveAndClose()
}
</script>

<template>
  <div class="text-sm">
    <div v-if="!editing" class="flex items-center gap-2">
      <div class="flex-1 space-y-1 max-h-24 overflow-y-auto">
        <div
          v-for="obs in observations"
          :key="obs.id"
          class="text-gray-300 whitespace-pre-wrap"
        >
          {{ obs.observation }}
          <span class="text-gray-500 text-xs ml-1">— {{ obs.created_by?.name }}</span>
        </div>
        <div v-if="!observations.length" class="text-gray-500 italic">Sin observaciones</div>
      </div>
      <button
        type="button"
        class="text-blue-400 hover:text-blue-300 text-xs shrink-0"
        @click="openEditor"
      >
        + Agregar
      </button>
    </div>

    <div v-else class="relative">
      <textarea
        ref="textareaRef"
        v-model="text"
        rows="2"
        class="w-full bg-gray-900 text-white border border-blue-500 rounded p-1 text-sm resize-none"
        placeholder="Escribi una observacion... (Enter guarda, Shift+Enter salto de linea, Esc cancela)"
        @keydown="onKeydown"
        @blur="onBlur"
      ></textarea>
      <span v-if="saving" class="absolute right-1 top-1 text-xs text-gray-400">Guardando...</span>
    </div>

    <div v-if="feedback === 'ok'" class="text-green-400 text-xs mt-0.5">Guardado ✓</div>
    <div v-if="feedback === 'error'" class="text-red-400 text-xs mt-0.5">Error al guardar, reintenta</div>
  </div>
</template>
