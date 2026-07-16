<script setup>
/**
 * Fase 18: ya no usa AuthenticationCard (queda igual para las otras 9
 * pantallas de auth que la comparten, ver propuesta). Login tiene su propio
 * layout completo -- el formulario es el protagonista, sin repetir la
 * identidad visual grande de Welcome (aca el logo/marca son chicos, el
 * titulo es funcional, no de marca).
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Ingresar" />

    <div class="min-h-screen bg-gray-900 flex items-center justify-center px-4 py-10">
        <div class="fade-in-up w-full max-w-sm bg-gray-800 border border-gray-700 rounded-lg p-8 shadow-xl">
            <div class="flex justify-center mb-6">
                <Link href="/">
                    <ApplicationMark class="h-12 w-auto rounded-md" />
                </Link>
            </div>

            <h1 class="text-base font-semibold text-white text-center mb-6">Ingresá a tu cuenta</h1>

            <div v-if="status" class="mb-4 text-sm text-green-400 text-center">
                {{ status }}
            </div>

            <form @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm text-gray-400 mb-1">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full bg-gray-900 border border-gray-600 text-white rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-700 focus:border-red-700"
                    />
                    <p v-if="form.errors.email" class="text-red-400 text-xs mt-1">{{ form.errors.email }}</p>
                </div>

                <div class="mt-4">
                    <label for="password" class="block text-sm text-gray-400 mb-1">Contraseña</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full bg-gray-900 border border-gray-600 text-white rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-700 focus:border-red-700"
                    />
                    <p v-if="form.errors.password" class="text-red-400 text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div class="flex items-center justify-between mt-4 text-sm">
                    <label class="flex items-center gap-2 text-gray-400">
                        <input v-model="form.remember" type="checkbox" class="accent-red-700" />
                        Recordarme
                    </label>
                    <Link v-if="canResetPassword" :href="route('password.request')" class="text-gray-400 hover:text-white underline">
                        ¿Olvidaste tu contraseña?
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full mt-6 bg-red-700 hover:bg-red-600 disabled:opacity-50 text-white font-semibold text-sm rounded-md px-4 py-2.5 transition"
                >
                    Ingresar
                </button>
            </form>

            <p class="text-center text-xs text-gray-500 mt-6">
                ¿Todavía no tenés cuenta?
                <Link :href="route('register')" class="text-gray-400 hover:text-white underline">Crear una cuenta</Link>
            </p>
            <p class="text-center text-[11px] text-gray-600 mt-1.5">
                Las nuevas cuentas requieren la aprobación de un administrador antes de poder ingresar.
            </p>
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
