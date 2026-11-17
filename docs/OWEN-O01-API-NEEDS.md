# O01 — necesidades del cliente para el contrato con Bryan

Estado: necesidades del cliente confrontadas con `docs/B01-ARCHITECTURE-DISCOVERY.md` de `block/bryan-b01` en `6456195`. Owen recibió autorización para usar esa propuesta como contrato de desarrollo; el backend todavía no existe. `src/client/apiTransport.ts` prepara transporte del mismo origen y `src/client/rumboApi.ts` adapta rutas y envelopes documentados. La aplicación continúa en modo local.

## Decisiones que B01 y O01 deben cerrar primero

1. **Identidad:** rutas y respuestas de registro, inicio/cierre, sesión vigente, verificación y recuperación. El cliente necesita saber si se usan cookies de sesión, protección CSRF, expiración y renovación; nunca recibirá credenciales de base de datos.
2. **Dinero:** el contrato B01 usa HNL como importe principal en lempiras con hasta dos decimales; USD es solo referencia informativa. Faltan máximo permitido, regla de normalización y significado preciso de saldo total, disponible, pendiente y proyectado. El cliente actual también usa `number` en lempiras.
3. **IDs y fechas:** formato de IDs emitidos por servidor; `YYYY-MM-DD` para fecha financiera local y UTC ISO 8601 para instantes. Zona horaria del titular por defecto `America/Tegucigalpa`.
4. **Pago recurrente:** decidir si confirmar un recordatorio crea un movimiento, cuándo se crea el siguiente vencimiento y cómo se revierte. El resultado debe ser atómico e idempotente. La regla documental existente contradice el código actual.
5. **Migración local:** si se permitirá importar un espacio cifrado del navegador, definir consentimiento, mapeo de IDs, deduplicación, operación de importación, confirmación y estrategia de recuperación. No borrar datos locales al fallar.
6. **Alcance del MVP:** confirmar si plantillas, preferencias, informes y asistente consumen API en esta etapa; ocultar o mantener como simulación las membresías y la prueba de siete días.

## Recursos y operaciones que pide el cliente

Las rutas de B01 son **contrato autorizado para avanzar en el cliente**, aunque todavía no son endpoints operativos. Cada recurso financiero pertenece al usuario de la sesión; el cliente no envía un `userId` de autoridad.

| Recurso candidato | Operaciones cliente necesarias |
|---|---|
| Sesión (`/api/v1/auth/session`) y perfil (`/api/v1/me/profile`) | Obtener sesión y perfil; cerrar sesión; actualizar preferencias. B01 documenta rutas de alta y recuperación, pero faltan payloads específicos. |
| Movimientos (`/api/v1/movements`) | Listar por periodo/tipo/categoría con cursor; crear, editar y borrar; distinguir confirmado de planificado. |
| Presupuestos (`/api/v1/budgets`) | Listar, crear o actualizar límite por categoría y periodo. |
| Metas (`/api/v1/goals`) y aportes (`/api/v1/goals/{id}/contributions`) | Listar y editar metas; registrar aporte con clave de idempotencia; devolver meta y saldo actualizados. |
| Recordatorios (`/api/v1/reminders`) | Listar, crear, editar y borrar; confirmar y revertir pago mediante una operación específica que devuelva recordatorio, movimiento y próxima cuota afectados. |
| Plantillas | B01 no define rutas todavía; mantenerlas locales hasta acordar alcance. |
| Resumen diario (`/api/v1/reports/daily`) | Totales diarios derivados; falta una ruta contractual de resumen general del panel. |
| Importación (`/api/v1/imports/local`) | Solo si se aprueba migrar datos locales; requerirá vista previa y resultado por registro. |

## Forma de respuesta a acordar

- Éxito: `{ "data": ..., "meta": { "requestId": "..." } }`. Las listas usan `data` como arreglo y `meta.nextCursor` / `meta.hasMore`. Filtros, orden y límite siguen pendientes.
- Errores: `{ "error": { "code", "message", "details", "requestId" } }`. Distinguir validación, conflicto, sin sesión, sin permiso, no encontrado y fallo temporal.
- Mutaciones: devolver representación confirmada por servidor y estado final suficiente para actualizar la pantalla. Reintentos de creación, pago y aporte llevan clave de idempotencia; no reutilizar IDs generados con `Date.now()` como identidad remota.
- Seguridad: la API deriva la propiedad de la sesión; respuestas de un titular nunca incluyen registros de otro. No registrar importes o notas personales en telemetría innecesaria.
- Versionado: conservar `/api/v1` o un mecanismo equivalente y acordar compatibilidad de cambios antes de conectar el frontend.

## Entrega de coordinación

Bryan debe cerrar los campos específicos de cada recurso, payloads de autenticación, reglas de paginación, semántica de saldos y pago/reversión de recordatorios, y plan de importación local. Owen implementará las pantallas conectadas cuando estén disponibles esas respuestas; las pruebas con mocks no se reportarán como integración real.
