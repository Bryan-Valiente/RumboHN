# O01 — necesidades del cliente para el contrato con Bryan

Estado: propuesta para B01, no contrato aprobado ni API implementada. Basada en el cliente de `main` `a28aa29`. `src/client/apiTransport.ts` prepara transporte del mismo origen sin fijar rutas ni interpretar modelos financieros; todavía no está conectado a `App.tsx`.

## Decisiones que B01 y O01 deben cerrar primero

1. **Identidad:** rutas y respuestas de registro, inicio/cierre, sesión vigente, verificación y recuperación. El cliente necesita saber si se usan cookies de sesión, protección CSRF, expiración y renovación; nunca recibirá credenciales de base de datos.
2. **Dinero:** moneda HNL, centavos enteros en transporte, máximo permitido, redondeo y significado preciso de saldo total, disponible, pendiente y proyectado. El cliente actual usa `number` en lempiras.
3. **IDs y fechas:** formato de IDs emitidos por servidor; `YYYY-MM-DD` para fecha financiera local y UTC ISO 8601 para instantes. Zona horaria del titular por defecto `America/Tegucigalpa`.
4. **Pago recurrente:** decidir si confirmar un recordatorio crea un movimiento, cuándo se crea el siguiente vencimiento y cómo se revierte. El resultado debe ser atómico e idempotente. La regla documental existente contradice el código actual.
5. **Migración local:** si se permitirá importar un espacio cifrado del navegador, definir consentimiento, mapeo de IDs, deduplicación, operación de importación, confirmación y estrategia de recuperación. No borrar datos locales al fallar.
6. **Alcance del MVP:** confirmar si plantillas, preferencias, informes y asistente consumen API en esta etapa; ocultar o mantener como simulación las membresías y la prueba de siete días.

## Recursos y operaciones que pide el cliente

Los nombres siguientes son **rutas candidatas**, sujetas a B01. Cada recurso financiero pertenece al usuario de la sesión; el cliente no envía un `userId` de autoridad.

| Recurso candidato | Operaciones cliente necesarias |
|---|---|
| Sesión y perfil (`/api/v1/session`, `/api/v1/me`) | Obtener sesión y perfil; cerrar sesión; actualizar preferencias. Las rutas de alta y recuperación dependen del sistema de identidad elegido. |
| Movimientos (`/api/v1/movements`) | Listar por periodo/tipo/categoría con cursor; crear, editar y borrar; distinguir confirmado de planificado. |
| Presupuestos (`/api/v1/budgets`) | Listar, crear o actualizar límite por categoría y periodo. |
| Metas (`/api/v1/goals`) y aportes (`/api/v1/goals/{id}/contributions`) | Listar y editar metas; registrar aporte con clave de idempotencia; devolver meta y saldo actualizados. |
| Recordatorios (`/api/v1/reminders`) | Listar, crear, editar y borrar; confirmar y revertir pago mediante una operación específica que devuelva recordatorio, movimiento y próxima cuota afectados. |
| Plantillas (`/api/v1/movement-templates`) | CRUD si entran en el MVP. |
| Resumen (`/api/v1/summary`) | Totales por periodo y zona horaria, con definiciones de saldos acordadas. |
| Importación (`/api/v1/imports/local`) | Solo si se aprueba migrar datos locales; requerirá vista previa y resultado por registro. |

## Forma de respuesta a acordar

- Listas: `items`, `nextCursor` y total solo si el servidor puede calcularlo de forma estable. Filtros y orden deben tener semántica definida.
- Errores: código de máquina estable, mensaje apto para usuario, errores por campo cuando aplique y un identificador de solicitud para soporte. Distinguir validación, conflicto, sin sesión, sin permiso, no encontrado y fallo temporal.
- Mutaciones: devolver representación confirmada por servidor y estado final suficiente para actualizar la pantalla. Reintentos de creación, pago y aporte llevan clave de idempotencia; no reutilizar IDs generados con `Date.now()` como identidad remota.
- Seguridad: la API deriva la propiedad de la sesión; respuestas de un titular nunca incluyen registros de otro. No registrar importes o notas personales en telemetría innecesaria.
- Versionado: conservar `/api/v1` o un mecanismo equivalente y acordar compatibilidad de cambios antes de conectar el frontend.

## Entrega de coordinación

Bryan puede responder a este documento con el contrato B01: rutas efectivas, esquema JSON de ejemplo, códigos de error, autenticación, paginación, semántica financiera y plan de importación. Owen implementará el adaptador contra esa versión aprobada. Hasta entonces, cualquier respuesta simulada se etiquetará como tal y ninguna prueba con mocks se reportará como integración real.
