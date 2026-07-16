<script setup>
/**
 * Filtro directo por Rover, independiente del buscador general.
 * 'rovers' llega vacio desde el backend si el usuario no tiene
 * 'pedidos.ver-todos' (un Rover no deberia poder ni ver la lista de
 * otros Rovers), asi que el componente se auto-oculta en ese caso.
 */
import { router } from '@inertiajs/vue3'

const props = defineProps({
  rovers: { type: Array, default: () => [] },
  modelValue: { type: [Number, String], default: 'all' },
})

function onChange(e) {
  const value = e.target.value
  router.get(
    window.location.pathname,
    { ...route_query_preserved(), rover_id: value === 'all' ? undefined : value },
    { preserveState: true, preserveScroll: true, replace: true }
  )
}

function route_query_preserved() {
  const params = new URLSearchParams(window.location.search)
  const obj = {}
  params.forEach((v, k) => { if (k !== 'rover_id') obj[k] = v })
  return obj
}
</script>

<template>
  <select
    v-if="rovers.length"
    :value="modelValue"
    class="bg-gray-800 text-gray-200 border border-gray-600 rounded-md px-2 py-1 text-sm"
    @change="onChange"
  >
    <option value="all">Rover: todos</option>
    <option v-for="rover in rovers" :key="rover.id" :value="rover.id">
      {{ rover.name }}
    </option>
  </select>
</template>
