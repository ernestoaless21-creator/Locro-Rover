<script setup>
/**
 * Fase 18: reemplaza el splash generico de Laravel. Un usuario ya logueado
 * que entra a "/" es redirigido a /orders en el backend (Fase 7), asi que
 * esta pantalla la ve unicamente un visitante sin sesion -- no hace falta
 * una variante "ya logueado".
 */
import { Head, Link } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});
</script>

<template>
    <Head title="Locro Rover" />

    <div class="min-h-screen bg-gray-900 flex items-center justify-center px-4 py-16">
        <div class="fade-in-up flex flex-col items-center text-center max-w-md">
            <ApplicationMark class="h-20 w-auto rounded-lg mb-6" />

            <h1 class="text-3xl font-bold text-white tracking-tight">Locro Rover</h1>
            <p class="text-gray-400 font-semibold text-sm tracking-wide mt-2">Memoria Histórica del Locro</p>

            <p class="text-gray-400 text-sm leading-relaxed mt-4">
                Una herramienta creada para preservar el conocimiento acumulado de cada edición del
                Locro y facilitar la organización del trabajo de la Comunidad.
            </p>

            <Link
                v-if="canLogin"
                :href="route('login')"
                class="mt-8 inline-flex items-center gap-2 bg-red-700 hover:bg-red-600 text-white font-semibold text-sm px-6 py-2.5 rounded-md transition"
            >
                Iniciar sesión
            </Link>

            <div v-if="canRegister" class="mt-5 text-center">
                <p class="text-gray-600 text-xs">¿Todavía no tenés usuario?</p>
                <Link :href="route('register')" class="text-gray-500 hover:text-gray-300 text-xs underline">
                    Crear una cuenta
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.fade-in-up {
    animation: fadeInUp 220ms ease-out both;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    .fade-in-up { animation: none; }
}
</style>
