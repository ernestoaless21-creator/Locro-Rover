# Roadmap — Locro App

Visión general del proyecto: qué ya está construido y hacia dónde va.

Locro App es la herramienta interna de gestión del Locro anual: organiza pedidos, clientes, equipos operativos, logística, compras, publicidad, cronograma y documentación de cada edición del evento.

---

## ✅ Completado

**Base (Fases 1–6).** Autenticación, equipos, modelo de datos de Clientes/Pedidos/Ediciones, roles y permisos iniciales.

**Operación diaria (Fases 7–8).** Pedidos como pantalla principal, indicadores compactos, filtros por Rover/estado/entrega, ranking de ventas por Rover.

**Equipos y trabajo interno (Fases 9–17).** Equipos operativos con checklist, importación de tareas entre ediciones, documentación de equipo, actas y reuniones, cronograma operativo, planificación de compras y proveedores, inventario de infraestructura, publicidad histórica, logística histórica.

**Rediseño visual y cierre de seguridad (Fase 18).** Tema oscuro real con Design System propio (tokens de color, componentes base reutilizables), rediseño de Landing/Login/navegación, simplificación del alta de pedidos y unificación del registro de pagos, y corrección del modelo de autorización: un Rover común ya solo puede editar sus propios pedidos y clientes asignados, con la restricción aplicada en el backend (no solo en la interfaz) y cubierta por tests automatizados.

---

## 🚧 Próximas fases

Sin un orden de prioridad definitivo todavía; agrupado por área.

### Dashboard 2.0
- KPIs operativos consolidados por edición.
- Indicadores de avance (producción, ventas, cobros) pensados para un vistazo rápido, no solo tablas.
- Analíticas propias de la organización (no solo lo que hoy expone `DashboardController`).
- Rediseño del ranking como parte de este dashboard, reutilizando el mismo lenguaje visual que Pedidos.
- Visibilidad de producción real (elaboradas/regaladas/perdidas/disponibles) integrada, no como pantallas separadas.

### Infraestructura técnica
- Base de datos definitiva (hoy SQLite en desarrollo).
- Hosting y despliegue formal, con HTTPS.
- Backups automáticos.
- Logs de aplicación y auditoría (`auditoria.ver` ya existe como permiso, falta la implementación).
- Optimización general (queries, cachés, tamaño de bundle del frontend).
- Seeds realistas para entornos de prueba, separados de los datos de producción.
- Carga de usuarios reales de la organización (hoy el entorno de desarrollo solo tiene cuentas de prueba).

### Memoria Histórica
- Estadísticas históricas entre ediciones (no solo el detalle de una edición puntual).
- Comparativas año a año (ventas, producción, recaudación).
- Documentación fotográfica de cada edición.
- Exportaciones (Excel/PDF) más allá de la exportación de Asignaciones que ya existe.

---

## Futuro

Ideas que todavía no son prioridad, para no perderlas de vista:

- Notificaciones (recordatorios de tareas, avisos de cronograma).
- App/versión optimizada para uso desde el celular durante el día del Locro.
- Integración de medios de pago electrónicos más allá del registro manual actual.
- Roles y permisos configurables desde la interfaz, sin tocar el seeder.
