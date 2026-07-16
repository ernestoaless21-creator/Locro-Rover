# Changelog

Todos los cambios importantes del proyecto Locro App.

El proyecto no sigue un esquema de versionado semántico tradicional: se organiza en **fases**, cada una entregando un bloque de funcionalidad completo para la organización del Locro anual. Este changelog documenta esas fases en orden cronológico.

---

## Fases 1–6 — Base del sistema (pre-historial detallado)

Sentaron la base de la aplicación: autenticación (Jetstream), gestión de equipos, modelo de datos de Clientes/Pedidos/Ediciones (`Year`), roles y permisos iniciales (Spatie Permission), y el flujo original de carga de pedidos. No quedaron documentadas fase por fase en el historial de git (se heredan del commit `958820f` — "Estado funcional antes de usar Claude Code"), pero son la base sobre la que se construyó todo lo siguiente.

## Fase 7 — Pedidos como pantalla principal

- Pedidos pasa a ser la pantalla de inicio real de la aplicación (antes lo era el Dashboard).
- Franja de indicadores compactos (porciones vendidas, salsas, por retirar, retiradas, producidas, vendidas por mí).
- Filtros por Rover, estado de retiro, estado de pago y tipo de entrega (delivery/retiro en mano).
- Buscador de clientes con autocompletado y advertencia (no bloqueante) de pedido duplicado para el mismo cliente/edición.
- Acción masiva "Cobrar y retirar seleccionados" para el día de entrega.
- Corrección de seguridad: `pedidos.eliminar` no estaba otorgado a ningún rol salvo `admin`; se sumó a Logística/Jefe de Logística.

## Fase 8 — Indicadores, filtros personales y ranking

- Gráfico de ventas por Rover (barras con degradado según % de meta, línea de meta — luego simplificado en Fase 18.1).
- Filtros rápidos personales: "Mis ventas", "Mis clientes asignados".
- Ampliación de indicadores y mejoras varias sobre Clientes (numeración histórica, asignación anual vía `ClientAssignment`).

## Fase 9 — Equipos operativos

- Modelo de equipos operativos (Logística, Compras, Infraestructura, Publicidad) con checklist avanzada de tareas.

## Fase 10 — Importación de tareas entre ediciones

- Permite traer la estructura de tareas de una edición anterior a la nueva, sin repetir la carga manual año a año.

## Fase 11 — Documentación de equipo

- Cada equipo puede adjuntar y organizar sus propios documentos de trabajo.

## Fase 12 — Actas y reuniones

- Gestión de actas de reunión, con adjuntos de documentos de equipo.

## Fases 13–14 — Cronograma operativo y planificación de compras

- Cronograma del evento por día/actividad, con seguimiento de horario previsto vs. real.
- Planificación de compras y proveedores por edición.

## Fase 15 — Inventario de infraestructura y permisos por integrante

- Inventario de infraestructura y préstamos.
- Los permisos de gestión de herramientas de equipo dejan de depender de ser "jefe": cualquier integrante activo del equipo puede gestionarlas.

## Fase 16 — Publicidad histórica

- Registro histórico de piezas y acciones de publicidad por edición.

## Fase 17 — Logística histórica

- Módulo de logística histórica (qué se hizo, cuándo, con qué recursos) por edición.

---

## Fase 18 — Rediseño visual y mejoras de UX

La fase más grande del proyecto hasta la fecha: migración completa a un tema oscuro propio, rediseño de la navegación, simplificación del flujo de Pedidos para el uso real del día del Locro, y el cierre de un conjunto de brechas de autorización que existían desde el diseño original de permisos ("todos venden, todos ven todo").

### UI / Design System

- **Landing (`Welcome.vue`) y Login rediseñados desde cero**, eliminando toda referencia visual a Jetstream/Laravel. El objetivo explícito: que la pantalla comunique "esta es la Memoria Histórica del Locro", no una landing comercial. Sin animaciones, sin métricas ni testimonios inventados.
- **Tema oscuro real, no un parche por pantalla.** Se formalizó como Design System la paleta que ya había quedado bien en Welcome/Login (no una paleta nueva): tokens `ink`, `surface`, `surface-2`, `surface-3`, `border`, `border-soft`, `ember`/`ember-strong`/`ember-wash` (rojo institucional), `herb` (éxito), `garnet` (eliminar), `maize` (advertencia), agregados a `tailwind.config.js`.
- **Componentes base reutilizables nuevos:** `AppButton` (variantes primary/secondary/success/danger — `PrimaryButton`/`SecondaryButton`/`SuccessButton`/`DangerButton` pasan a ser wrappers finos para no romper ~125 usos existentes), `Card`, `Badge`, `AppInput`, `AppSelect`, `FieldLabel`, `TableCard`, `EmptyState`.
- **Migración de `AppLayout.vue`** (nav, header, dropdowns, menú móvil) al tema oscuro, incluyendo el barrido de color de título en las ~30 páginas que definen su propio `<template #header>`.
- **Navegación de dos niveles rediseñada**: Pedidos y Clientes siempre sueltos en el primer nivel (son las pantallas de uso diario); "Equipos"/"Organización" (renombrada **"Documentación"** en Fase 18.1) agrupan el resto detrás de una segunda fila que solo aparece si el grupo tiene más de un ítem.
- Migración de contraste y consistencia visual específica en **Cronograma** (nombres de día y de tarea eran prácticamente ilegibles sobre el nuevo fondo oscuro) y **Parámetros** (tarjetas sin borde que se fundían con el fondo de la página, dando sensación de "espacio flotando" — causa real identificada mediante inspección del DOM, no por prueba y error de paddings).

### Pedidos

- Franja de métricas convertida en chips discretos: forma = interactividad (chip rectangular clickeable vs. pill de solo lectura), color reservado únicamente para los dos indicadores realmente operativos del día ("por retirar" en `maize`, "retiradas" en `herb`).
- Buscador con ancho flexible (antes fijo en 288px) para reflejar que es el control más usado de toda la aplicación, sin restarle protagonismo a "Nuevo pedido".
- "Nuevo pedido" movido junto al título, en la misma línea de lectura.
- **Alta de pedido simplificada para el Rover común**: el selector de edición desaparece (siempre se usa la edición activa; solo quien administra ediciones puede elegir otra) y el registro de pago queda integrado en el mismo formulario de alta (uno o varios medios de pago con su monto, sin tener que abrir el pedido después para cobrar).
- **Registro de pagos unificado**: se eliminó el toggle "Pagar saldo total" / "Montos específicos" — una sola interfaz de líneas {medio de pago, monto}, con el saldo pendiente precargado como ayuda editable.
- **Sobrepago con estado explícito**: un saldo negativo ya no se muestra como un número negativo silencioso; aparece "Sobrepago: debe devolver $X" en rojo, calculado en vivo mientras se completa el formulario de pago (sin la fricción de un segundo paso de confirmación).
- Ranking de ventas simplificado: se quitó la línea de meta superpuesta (redundante con el degradado de color, generaba sensación de "barra cortada"); con más de 10 Rovers, se muestra el Top 10 con un botón "Ver ranking completo".

### Cronograma

- Barrido de colores tipográficos que habían quedado pensados para fondo claro (título del día, nombre de la tarea, textos secundarios) y ahora eran casi ilegibles sobre el fondo oscuro. Enlaces de acción sueltos migrados de `indigo` a `ember` para consistencia con el resto de la aplicación.

### Parámetros

- Causa real del espacio "flotando" bajo el encabezado: la tarjeta de "Ediciones" usaba el mismo color exacto que el fondo de la página, sin borde, así que no tenía ningún límite visual perceptible. Se corrigió llevándola al mismo tratamiento `surface`/`border` que el resto del Design System, y se ajustó su padding (`p-6` → `p-5`) para igualar el único precedente comparable de la aplicación (las tarjetas de Pedidos).

### Seguridad

- **Corrección de un modelo de permisos que asumía "todos venden, todos ven y editan todo".** Antes, `pedidos.ver-todos` y `clientes.editar` (comunes a todos los roles operativos) también se usaban para decidir quién podía **editar** un recurso ajeno, no solo verlo. Un Rover común podía editar pedidos y clientes de cualquier otro Rover.
- `OrderPolicy::update` y `assignRover` ahora exigen `pedidos.asignar-rover` (exclusivo de Admin/Logística) para tocar un pedido ajeno o reasignar responsable; un Rover común solo puede editar pedidos donde `rover_id` es él mismo.
- `ClientPolicy::update` ahora exige ser el responsable asignado del cliente en la **edición activa** (`client_year_assignments.assigned_user_id`, no un campo `user_id` directo en `Client`) o tener `asignaciones.transferir` (Admin/Logística).
- Ningún permiso nuevo: ambas reglas reutilizan permisos ya existentes con exactamente ese alcance, evitando duplicar el modelo de autorización.
- La restricción se aplica en el backend (Policies + Form Requests), no solo ocultando botones: una URL directa, DevTools o una request modificada reciben `403` igual.
- Frontend actualizado en consecuencia: los enlaces "Editar" en `Orders/Index.vue` y `Clients/Index.vue` se ocultan cuando el recurso no pertenece al usuario (en vez de llevarlo a un 403).

### Testing

- `tests/Feature/RoverOwnershipRestrictionTest.php`: 12 tests que verifican, contra las rutas reales (no simulando la UI), que un Rover común no puede editar pedidos/clientes ajenos ni reasignar responsables por ninguna de las vías existentes (`PUT /orders/{id}`, `POST /orders/bulk-assign`, `PUT /clients/{id}`, `POST /clients/{id}/assignment/transfer`, `POST /assignments/{id}/transfer`), que sí puede seguir editando lo propio, y que Admin y Logística conservan acceso total.
