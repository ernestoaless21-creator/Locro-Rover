<script setup>
/**
 * Fase 5C — Parte 1. Se muestra cuando EnsureUserHasRole redirige a un
 * usuario autenticado que todavia no tiene ningun rol asignado. Reemplaza el
 * 403 generico que recibia antes al entrar al Dashboard.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticationCard from '@/Components/AuthenticationCard.vue'
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue'

function logout() {
  router.post(route('logout'))
}
</script>

<template>
  <Head title="Cuenta pendiente de autorización" />

  <AuthenticationCard>
    <template #logo>
      <AuthenticationCardLogo />
    </template>

    <div class="text-center space-y-4">
      <h1 class="text-lg font-semibold text-gray-900">
        Tu cuenta fue creada correctamente
      </h1>
      <p class="text-sm text-gray-600">
        Está pendiente de autorización. Un administrador debe asignarte un rol
        antes de que puedas acceder al sistema. Podés volver a intentarlo más
        tarde, o consultar con un administrador de la comunidad.
      </p>

      <div class="flex flex-col gap-2 pt-2">
        <Link :href="route('profile.show')" class="text-sm text-blue-600 hover:text-blue-800">
          Ver mi perfil
        </Link>
        <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="logout">
          Cerrar sesión
        </button>
      </div>
    </div>
  </AuthenticationCard>
</template>
