# Locro App

Herramienta interna para organizar el Locro anual: pedidos, clientes, equipos operativos, logística, compras, publicidad, cronograma y documentación de cada edición del evento.

No es un producto comercial ni una landing pública — es una aplicación de uso interno para quienes organizan y venden en cada edición.

## Objetivo

Reemplazar el trabajo manual (planillas sueltas, mensajes dispersos) por un sistema único donde:

- se cargan y siguen los pedidos de cada edición, con el registro de pagos y el estado de retiro;
- se administra la cartera de clientes y quién es responsable de cada uno;
- cada equipo (Logística, Compras, Infraestructura, Publicidad) gestiona sus propias tareas, documentos e inventario;
- queda memoria histórica de cada edición para comparar y planificar la siguiente.

## Stack tecnológico

- **Backend:** Laravel 12 (PHP 8.2+), Jetstream + Sanctum para autenticación, Spatie Laravel Permission para roles/permisos.
- **Frontend:** Vue 3 (`<script setup>`) + Inertia.js — sin API REST separada, el backend renderiza componentes Vue directamente.
- **Estilos:** Tailwind CSS 3, con un Design System propio de tema oscuro (ver `tailwind.config.js` y `CHANGELOG.md`, Fase 18).
- **Build:** Vite.
- **Base de datos (desarrollo):** SQLite.
- **Tests:** PHPUnit (feature tests sobre rutas reales, no mocks de autorización).

## Instalación

Requisitos: PHP 8.2+, Composer, Node.js 18+, npm.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

El seeder (`RolesAndPermissionsSeeder`) crea los roles operativos (`admin`, `logistica`, `compras`, `infraestructura`, `publicidad`, y sus variantes `jefe_*`), los medios de pago iniciales (Efectivo, Transferencia) y la edición activa del año en curso. No crea usuarios: se registran desde `/register` (las cuentas nuevas quedan pendientes de aprobación de un administrador).

## Ejecución local

```bash
composer run dev
```

Levanta en paralelo el servidor de Laravel, el worker de colas, los logs (`pail`) y Vite en modo desarrollo. Alternativamente, por separado:

```bash
php artisan serve
npm run dev
```

Para generar el build de producción del frontend:

```bash
npm run build
```

## Tests

```bash
php artisan test
```

Para correr un archivo o caso puntual:

```bash
php artisan test --filter=NombreDelTest
```

## Estructura general

```
app/
  Http/Controllers/    # Un controller por módulo (Order, Client, Meeting, Schedule, ...)
  Http/Requests/       # Form Requests: autorizacion + validacion de cada request
  Models/               # Eloquent (Order, Client, ClientAssignment, Year, ...)
  Policies/             # Reglas de autorizacion por modelo (OrderPolicy, ClientPolicy, ...)
  Services/             # Logica de negocio reutilizada entre controllers (PricingService, ClientAssignmentService)

resources/js/
  Components/           # Componentes compartidos: Design System (AppButton, Card, Badge, ...) + modales
  Layouts/AppLayout.vue # Layout principal (nav, header, tema oscuro)
  Pages/                # Una carpeta por modulo, resuelta directamente por Inertia
    Orders/  Clients/  Teams/  Schedule/  Purchases/  Infrastructure/  Publicity/  Logistics/  Meetings/  Years/  Users/  ...

database/
  migrations/           # Esquema
  seeders/              # RolesAndPermissionsSeeder (roles, permisos, medios de pago, edicion activa)

tests/Feature/          # Un archivo por feature/correccion, contra rutas reales
routes/web.php          # Todas las rutas de la aplicacion (no hay API separada)
```

Cada módulo operativo (Compras, Infraestructura, Publicidad, Logística) sigue el mismo patrón: un controller, permisos propios (`{modulo}.ver` / `{modulo}.gestionar`) y una vista `Index.vue` con historial por edición.

## Documentación adicional

- `CHANGELOG.md` — historial de fases del proyecto.
- `ROADMAP.md` — qué está completado y qué sigue.
