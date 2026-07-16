<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

defineProps({
    title: String,
});

const page = usePage();
const can = (perm) => (page.props.permissions ?? []).includes(perm);

// Fase 18: navegacion de dos niveles (Propuesta C). Ajuste tras la prueba de
// uso: Pedidos (pantalla principal) y Clientes quedan SIEMPRE sueltos en el
// primer nivel, navegando directo — ya no integran un grupo "Ventas". Solo
// "Equipos"/"Mi equipo" y "Organización" siguen siendo grupos con segunda
// fila. Un grupo que termina con un unico item permitido igual se muestra
// como link directo (sin fila redundante), regla que ya existia.
const topLinks = computed(() => [
    can('pedidos.ver') && {
        key: 'pedidos',
        icon: 'soup',
        label: 'Pedidos',
        href: route('orders.index'),
        active: route().current('orders.*'),
    },
    can('clientes.ver') && {
        key: 'clientes',
        icon: 'users',
        label: 'Clientes',
        href: route('clients.index'),
        active: route().current('clients.*') || route().current('assignments.*'),
    },
].filter(Boolean));

// navGroups es la unica fuente de verdad para "Equipos/Mi equipo" y
// "Organización", reutilizada por el nav de escritorio y el menu responsive.
const navGroups = computed(() => {
    const groups = [
        {
            id: 'equipo',
            // Fase 18 (ajuste): "Equipos" en plural solo para quien administra
            // todos los equipos; "Mi equipo" se mantiene igual para miembros.
            label: can('equipos.gestionar-todos') ? 'Equipos' : 'Mi equipo',
            icon: 'briefcase',
            items: can('equipos.gestionar-todos')
                ? [
                      { label: 'Logística', href: route('teams.show', 'logistica'), active: page.url.startsWith('/teams/logistica') },
                      { label: 'Compras', href: route('teams.show', 'compras'), active: page.url.startsWith('/teams/compras') },
                      { label: 'Infraestructura', href: route('teams.show', 'infraestructura'), active: page.url.startsWith('/teams/infraestructura') },
                      { label: 'Publicidad', href: route('teams.show', 'publicidad'), active: page.url.startsWith('/teams/publicidad') },
                      { label: 'Importar tareas', href: route('teams.import'), active: page.url.startsWith('/teams/import') },
                  ]
                : page.props.userTeam
                    ? [{ label: 'Mi equipo', href: route('teams.show', page.props.userTeam), active: route().current('teams.*') }]
                    : [],
        },
        {
            id: 'organizacion',
            // Fase 18.1: solo cambia el texto visible del menu (a pedido del
            // usuario); id, rutas y estructura interna quedan iguales.
            label: 'Documentación',
            icon: 'building',
            items: [
                can('actas.ver') && { label: 'Actas', href: route('meetings.index'), active: route().current('meetings.*') },
                can('cronograma.ver') && { label: 'Cronograma', href: route('schedule.index'), active: route().current('schedule.*') },
                can('usuarios.gestionar') && { label: 'Usuarios', href: route('users.index'), active: route().current('users.*') },
                can('parametros.gestionar') && { label: 'Parámetros', href: route('parameters.index'), active: route().current('parameters.*') },
            ].filter(Boolean),
        },
    ];
    return groups.filter((g) => g.items.length > 0);
});

// Grupo activo segun la ruta real (null si la ruta actual es Pedidos/Clientes,
// asi la fila 2 no se muestra); arranca ahi y el click en una pestaña de
// grupo lo puede cambiar sin navegar (solo revela la segunda fila).
const activeGroupId = computed(() => navGroups.value.find((g) => g.items.some((i) => i.active))?.id ?? null);
const selectedGroupId = ref(activeGroupId.value);
const selectedGroup = computed(() => navGroups.value.find((g) => g.id === selectedGroupId.value) ?? null);

const showingNavigationDropdown = ref(false);

const switchToTeam = (team) => {
    router.put(route('current-team.update'), {
        team_id: team.id,
    }, {
        preserveState: false,
    });
};

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />

        <Banner />

        <div class="min-h-screen bg-ink">
            <nav class="bg-surface border-b border-border">
                <!-- Primary Navigation Menu -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <Link :href="route('orders.index')">
                                    <ApplicationMark class="block h-11 w-auto" />
                                </Link>
                            </div>

                            <!-- Nivel 1: Pedidos y Clientes sueltos (navegan directo) + grupos
                                 Equipos/Mi equipo y Organización (con fila 2) -->
                            <div class="hidden sm:-my-px sm:ms-10 sm:flex sm:space-x-8">
                                <!-- Pedidos / Clientes: siempre visibles, nunca agrupados -->
                                <Link
                                    v-for="link in topLinks"
                                    :key="link.key"
                                    :href="link.href"
                                    class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out"
                                    :class="link.active
                                        ? 'border-ember text-white'
                                        : 'border-transparent text-gray-400 hover:text-white hover:border-gray-500'"
                                >
                                    <svg v-if="link.icon === 'soup'" class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9 9 0 0 0 9-9H3a9 9 0 0 0 9 9Z" /><path d="M7 21h10" /><path d="M19.5 12 22 6" /><path d="M16.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.73 1.62" /><path d="M11.25 3c.27.1.8.53.74 1.36-.05.83-.93 1.2-.98 2.02-.06.78.33 1.24.72 1.62" /><path d="M6.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.74 1.62" /></svg>
                                    <svg v-else class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><path d="M16 3.128a4 4 0 0 1 0 7.744" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><circle cx="9" cy="7" r="4" /></svg>
                                    {{ link.label }}
                                </Link>

                                <template v-for="group in navGroups" :key="group.id">
                                    <!-- Grupo de un solo item permitido: link directo, sin fila 2 -->
                                    <Link
                                        v-if="group.items.length === 1"
                                        :href="group.items[0].href"
                                        class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out"
                                        :class="group.items[0].active
                                            ? 'border-ember text-white'
                                            : 'border-transparent text-gray-400 hover:text-white hover:border-gray-500'"
                                    >
                                        <svg v-if="group.icon === 'briefcase'" class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" /><rect width="20" height="14" x="2" y="6" rx="2" /></svg>
                                        <svg v-else class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 12h4" /><path d="M10 8h4" /><path d="M14 21v-3a2 2 0 0 0-4 0v3" /><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2" /><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" /></svg>
                                        {{ group.items[0].label }}
                                    </Link>

                                    <!-- Grupo con varias paginas: pestaña que revela la fila 2 -->
                                    <button
                                        v-else
                                        type="button"
                                        class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out"
                                        :class="selectedGroupId === group.id
                                            ? 'border-ember text-white'
                                            : 'border-transparent text-gray-400 hover:text-white hover:border-gray-500'"
                                        @click="selectedGroupId = selectedGroupId === group.id ? null : group.id"
                                    >
                                        <svg v-if="group.icon === 'briefcase'" class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" /><rect width="20" height="14" x="2" y="6" rx="2" /></svg>
                                        <svg v-else class="w-4 h-4 me-1.5 shrink-0 text-ember" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 12h4" /><path d="M10 8h4" /><path d="M14 21v-3a2 2 0 0 0-4 0v3" /><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2" /><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" /></svg>
                                        {{ group.label }}
                                        <span class="ms-1 -me-0.5 text-[10px] leading-none">{{ selectedGroupId === group.id ? '▲' : '▼' }}</span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div class="hidden sm:flex sm:items-center sm:ms-6">
                            <div class="ms-3 relative">
                                <!-- Teams Dropdown -->
                                <Dropdown v-if="$page.props.jetstream.hasTeamFeatures" align="right" width="60">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-300 bg-surface hover:text-white focus:outline-none focus:bg-surface-3 active:bg-surface-3 transition ease-in-out duration-150">
                                                {{ $page.props.auth.user.current_team.name }}

                                                <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <div class="w-60">
                                            <!-- Team Management -->
                                            <div class="block px-4 py-2 text-xs text-gray-400">
                                                Manage Team
                                            </div>

                                            <!-- Team Settings -->
                                            <DropdownLink :href="route('teams.show', $page.props.auth.user.current_team)">
                                                Team Settings
                                            </DropdownLink>

                                            <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">
                                                Create New Team
                                            </DropdownLink>

                                            <!-- Team Switcher -->
                                            <template v-if="$page.props.auth.user.all_teams.length > 1">
                                                <div class="border-t border-border" />

                                                <div class="block px-4 py-2 text-xs text-gray-400">
                                                    Switch Teams
                                                </div>

                                                <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                                    <form @submit.prevent="switchToTeam(team)">
                                                        <DropdownLink as="button">
                                                            <div class="flex items-center">
                                                                <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>

                                                                <div>{{ team.name }}</div>
                                                            </div>
                                                        </DropdownLink>
                                                    </form>
                                                </template>
                                            </template>
                                        </div>
                                    </template>
                                </Dropdown>
                            </div>

                            <!-- Settings Dropdown -->
                            <div class="ms-3 relative">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <button v-if="$page.props.jetstream.managesProfilePhotos" class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-500 transition">
                                            <img class="size-8 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                                        </button>

                                        <span v-else class="inline-flex rounded-md">
                                            <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-300 bg-surface hover:text-white focus:outline-none focus:bg-surface-3 active:bg-surface-3 transition ease-in-out duration-150">
                                                {{ $page.props.auth.user.name }}

                                                <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <!-- Account Management -->
                                        <div class="block px-4 py-2 text-xs text-gray-400">
                                            Manage Account
                                        </div>

                                        <DropdownLink :href="route('profile.show')">
                                            Profile
                                        </DropdownLink>

                                        <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">
                                            API Tokens
                                        </DropdownLink>

                                        <div class="border-t border-border" />

                                        <!-- Authentication -->
                                        <form @submit.prevent="logout">
                                            <DropdownLink as="button">
                                                Log Out
                                            </DropdownLink>
                                        </form>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-surface-3 focus:outline-none focus:bg-surface-3 focus:text-white transition duration-150 ease-in-out" @click="showingNavigationDropdown = ! showingNavigationDropdown">
                                <svg
                                    class="size-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{'hidden': showingNavigationDropdown, 'inline-flex': ! showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{'hidden': ! showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Nivel 2: paginas del grupo seleccionado (solo si tiene mas de una).
                         Transicion corta (120ms, opacidad + desplazamiento vertical minimo)
                         solo para que se perciba que aparecio/desaparecio un submenu, sin
                         llamar la atencion (respeta prefers-reduced-motion, ver <style> abajo). -->
                    <Transition name="nav-tier2">
                        <div
                            v-if="selectedGroup && selectedGroup.items.length > 1"
                            class="hidden sm:flex sm:items-center sm:gap-2 border-t border-border py-2"
                        >
                            <Link
                                v-for="item in selectedGroup.items"
                                :key="item.label"
                                :href="item.href"
                                class="text-sm px-3 py-1 rounded-full transition-colors"
                                :class="item.active ? 'bg-ember text-white font-semibold' : 'text-gray-400 hover:text-white hover:bg-surface-3'"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </Transition>
                </div>

                <!-- Responsive Navigation Menu -->
                <div :class="{'block': showingNavigationDropdown, 'hidden': ! showingNavigationDropdown}" class="sm:hidden">
                    <div class="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink
                            v-for="link in topLinks"
                            :key="link.key"
                            :href="link.href"
                            :active="link.active"
                        >
                            {{ link.label }}
                        </ResponsiveNavLink>

                        <template v-for="group in navGroups" :key="group.id">
                            <div v-if="group.items.length > 1" class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ group.label }}
                            </div>
                            <ResponsiveNavLink
                                v-for="item in group.items"
                                :key="item.label"
                                :href="item.href"
                                :active="item.active"
                            >
                                {{ item.label }}
                            </ResponsiveNavLink>
                        </template>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="pt-4 pb-1 border-t border-border">
                        <div class="flex items-center px-4">
                            <div v-if="$page.props.jetstream.managesProfilePhotos" class="shrink-0 me-3">
                                <img class="size-10 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                            </div>

                            <div>
                                <div class="font-medium text-base text-white">
                                    {{ $page.props.auth.user.name }}
                                </div>
                                <div class="font-medium text-sm text-gray-400">
                                    {{ $page.props.auth.user.email }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.show')" :active="route().current('profile.show')">
                                Profile
                            </ResponsiveNavLink>

                            <ResponsiveNavLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')" :active="route().current('api-tokens.index')">
                                API Tokens
                            </ResponsiveNavLink>

                            <!-- Authentication -->
                            <form method="POST" @submit.prevent="logout">
                                <ResponsiveNavLink as="button">
                                    Log Out
                                </ResponsiveNavLink>
                            </form>

                            <!-- Team Management -->
                            <template v-if="$page.props.jetstream.hasTeamFeatures">
                                <div class="border-t border-border" />

                                <div class="block px-4 py-2 text-xs text-gray-400">
                                    Manage Team
                                </div>

                                <!-- Team Settings -->
                                <ResponsiveNavLink :href="route('teams.show', $page.props.auth.user.current_team)" :active="route().current('teams.show')">
                                    Team Settings
                                </ResponsiveNavLink>

                                <ResponsiveNavLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')" :active="route().current('teams.create')">
                                    Create New Team
                                </ResponsiveNavLink>

                                <!-- Team Switcher -->
                                <template v-if="$page.props.auth.user.all_teams.length > 1">
                                    <div class="border-t border-border" />

                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        Switch Teams
                                    </div>

                                    <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                        <form @submit.prevent="switchToTeam(team)">
                                            <ResponsiveNavLink as="button">
                                                <div class="flex items-center">
                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <div>{{ team.name }}</div>
                                                </div>
                                            </ResponsiveNavLink>
                                        </form>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header v-if="$slots.header" class="bg-surface shadow">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
/* Fase 18: feedback minimo de que aparecio/desaparecio la fila 2 (Equipos/
   Organización) — 120ms, opacidad + un desplazamiento vertical chico.
   Nada de esto debe notarse como "animacion", solo confirmar el cambio. */
.nav-tier2-enter-active,
.nav-tier2-leave-active {
    transition: opacity 120ms ease, transform 120ms ease;
}
.nav-tier2-enter-from,
.nav-tier2-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .nav-tier2-enter-active,
    .nav-tier2-leave-active {
        transition: none;
    }
}
</style>
