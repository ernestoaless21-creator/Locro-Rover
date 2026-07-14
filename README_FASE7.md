# Fase 7 — Archivos de esta entrega

Este ZIP contiene **solo los archivos nuevos o modificados** en esta fase, con
la misma ruta relativa que tienen dentro de tu proyecto Laravel. Los demás
archivos del proyecto (Gifts, Losses, Dashboard, Parameters, Users, etc.) **no
se tocaron**.

## Cómo integrar

1. Copiá cada archivo de este ZIP a la misma ruta dentro de tu proyecto real,
   sobrescribiendo el existente (o hacé el merge manual si ya tenías cambios
   propios en alguno de estos archivos).
2. Corré las migraciones nuevas:
   ```
   php artisan migrate
   ```
   Esto va a ejecutar, en orden:
   - `2026_07_15_000001_backfill_historical_numbers_for_existing_clients` —
     les asigna número histórico a los clientes que todavía no tenían.
   - `2026_07_15_000002_backfill_missing_client_year_assignments` — crea la
     asignación anual faltante para cualquier pedido existente cuyo cliente
     no tuviera fila en `client_year_assignments` (la causa del pedido que
     faltaba en la exportación).
3. Si vas a correr los tests nuevos, asegurate de tener una base de datos de
   testing configurada (sqlite en memoria o una BD de test aparte). Corré:
   ```
   php artisan test
   ```
   o los archivos puntuales de esta fase:
   ```
   php artisan test tests/Unit/ClientPhoneNormalizationTest.php
   php artisan test tests/Feature/ClientHistoricalNumberTest.php
   php artisan test tests/Feature/ClientSearchTest.php
   php artisan test tests/Feature/ClientAssignmentSyncTest.php
   php artisan test tests/Feature/OrderBulkPayAndWithdrawTest.php
   ```
4. No hace falta ningún `npm install` nuevo (no se agregaron dependencias de
   frontend), pero sí hay que recompilar assets si tu flujo de trabajo lo
   requiere: `npm run build` (o `npm run dev` en desarrollo).

**IMPORTANTE — nada de esto se ejecutó de mi lado**: no tengo PHP disponible
en este entorno, así que no pude correr `php artisan migrate`, `php artisan
test` ni ningún comando real. Todo lo de arriba es exactamente lo que
correrías vos. Revisé el código manualmente con mucho cuidado (incluyendo un
chequeo de balance de llaves y una relectura completa de cada archivo), pero
no hay sustituto de correrlo de verdad en tu entorno.

## Ver el reporte completo

El resumen completo de la fase — decisiones de arquitectura, qué se hizo en
cada una de las 15 secciones, riesgos pendientes y el checklist manual de
prueba — está en la respuesta de chat, no en este ZIP.
