<script setup>
/**
 * Fase 5C / 6A. Seccion "Usuarios": ver quien se registro, asignar/cambiar
 * su unico rol operativo (solo entre los 9 roles nuevos, Fase 6A seccion
 * 14), y activar/desactivar usuarios sin borrar su historial (seccion 3).
 */
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/Composables/useToast'
import { roleLabel } from '@/utils/roleLabels'

const props = defineProps({
  users: { type: Array, required: true },
  roles: { type: Array, required: true },
  authUserId: { type: Number, required: true },
})

const toast = useToast()
const savingId = ref(null)

function updateRole(user, role) {
  if (!role || role === user.role) return
  savingId.value = user.id
  router.put(
    `/users/${user.id}/role`,
    { role },
    {
      preserveScroll: true,
      onSuccess: () => toast.success(`Rol de ${user.name} actualizado.`),
      onError: () => toast.error('No se pudo actualizar el rol.'),
      onFinish: () => { savingId.value = null },
    }
  )
}

function toggleActive(user) {
  savingId.value = user.id
  const url = user.is_active ? `/users/${user.id}/deactivate` : `/users/${user.id}/reactivate`
  router.post(
    url,
    {},
    {
      preserveScroll: true,
      onSuccess: () => toast.success(user.is_active ? `${user.name} desactivado.` : `${user.name} reactivado.`),
      onError: (errors) => toast.error(errors?.error || 'No se pudo actualizar el estado.'),
      onFinish: () => { savingId.value = null },
    }
  )
}
</script>

<template>
  <Head title="Usuarios" />

  <AppLayout title="Usuarios">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Usuarios</h2>
    </template>

    <div class="py-8 max-w-5xl mx-auto px-4">
      <div class="bg-gray-900 text-white rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-800">
            <tr>
              <th class="p-3 text-left">Nombre</th>
              <th class="p-3 text-left">Email</th>
              <th class="p-3 text-left">Estado</th>
              <th class="p-3 text-left">Rol actual</th>
              <th class="p-3 text-left">Asignar / cambiar rol</th>
              <th class="p-3 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id" class="border-t border-gray-800" :class="{ 'opacity-50': !user.is_active }">
              <td class="p-3">{{ user.name }}</td>
              <td class="p-3 text-gray-400">{{ user.email }}</td>
              <td class="p-3">
                <span v-if="user.is_active" class="px-2 py-0.5 rounded-full text-xs bg-green-700">Activo</span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-red-800">Inactivo</span>
              </td>
              <td class="p-3">
                <span
                  v-if="user.role"
                  class="px-2 py-0.5 rounded-full text-xs"
                  :class="user.is_legacy_role ? 'bg-yellow-800' : 'bg-green-700'"
                >
                  {{ roleLabel(user.role) }}<span v-if="user.is_legacy_role"> (legacy)</span>
                </span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-yellow-700">
                  Sin rol — pendiente
                </span>
              </td>
              <td class="p-3">
                <select
                  class="bg-gray-800 border border-gray-600 rounded-md px-2 py-1 text-sm"
                  :value="user.role ?? ''"
                  :disabled="savingId === user.id"
                  @change="updateRole(user, $event.target.value)"
                >
                  <option value="" disabled>Elegir rol...</option>
                  <option v-for="role in roles" :key="role" :value="role">{{ roleLabel(role) }}</option>
                  <option v-if="user.is_legacy_role" :value="user.role" disabled>{{ roleLabel(user.role) }} (legacy, elegir uno nuevo)</option>
                </select>
              </td>
              <td class="p-3">
                <button
                  type="button"
                  class="px-3 py-1 rounded-md text-xs"
                  :class="user.is_active ? 'bg-red-800 hover:bg-red-700' : 'bg-green-700 hover:bg-green-600'"
                  :disabled="savingId === user.id"
                  @click="toggleActive(user)"
                >
                  {{ user.is_active ? 'Desactivar' : 'Reactivar' }}
                </button>
              </td>
            </tr>
            <tr v-if="!users.length">
              <td colspan="6" class="p-6 text-center text-gray-500">No hay usuarios registrados.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-gray-500 mt-3">
        Los roles marcados como "legacy" (rover / jefe_equipo) ya no se asignan a usuarios nuevos.
        Elegí uno de los roles nuevos para migrar a ese usuario cuando corresponda.
      </p>
    </div>
  </AppLayout>
</template>
