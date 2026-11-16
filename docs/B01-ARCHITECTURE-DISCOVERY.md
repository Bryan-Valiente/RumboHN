# RUMBO HN — B01-L01: descubrimiento de arquitectura y contrato API

Estado: descubrimiento completado; no implementa backend, base de datos, autenticación real ni despliegue.

Baseline revisado: `main` en `a28aa290cf2b5c7064a72bae7f27d931e636e6a9`.

## 1. Hallazgos confirmados

### Arquitectura actual

- Aplicación SPA frontend con React, TypeScript y Vite.
- Runtime actual: Node/npm para desarrollo, Vitest para pruebas y Vite para el bundle.
- No existen directorios `backend/`, `server/`, `api/`, migraciones SQL, esquema de base de datos ni cliente HTTP de aplicación.
- No existe configuración Hostinger, `.env` de runtime, dominio, credenciales, pool de conexiones ni servicio de correo verificable en el repositorio.
- `src/App.tsx` concentra el flujo de pantallas y estado; `src/finance.ts` contiene tipos, semillas ficticias y reglas financieras; `src/persistence.ts` contiene el almacenamiento local y la protección del workspace.

### Persistencia y autenticación actuales

- La clave local es `rumbo-hn-personal-workspace-v1`.
- El formato cifrado declarado es `rumbo-hn-encrypted-workspace`, versión `2`.
- La protección local usa PBKDF2-SHA-256 con 120 000 iteraciones para derivar una clave AES-GCM de 256 bits. La contraseña no se envía a un servidor ni se guarda.
- El navegador guarda un envelope con `schema`, `version`, `algorithm`, `kdf`, `iterations`, `salt`, `iv`, `ciphertext` y `createdAt`.
- También existe compatibilidad de lectura/migración explícita para el workspace legado en JSON. No debe borrarse ni convertirse automáticamente en datos remotos.
- El acceso actual es un desbloqueo local de la demo. El formulario de autenticación visual no constituye registro, sesión, recuperación ni aislamiento de cuentas.

### Regla financiera para el contrato

Los tipos actuales (`Movement`, `Budget`, `SavingsGoal`, `SavingsContribution` y `Reminder`) usan `number` y las semillas representan HNL directamente, por ejemplo `15000` para L 15,000. El contrato API usará HNL como moneda principal, expresada en lempiras con dos decimales. La función `calculateSavingsPlan` puede usar centavos internamente para redondear, pero ese detalle no forma parte del formato contractual.

La conversión opcional a USD podrá incluirse únicamente como referencia informativa. No sustituirá el importe HNL, no cambiará la moneda principal y no será autoridad para saldos, validaciones, pagos ni persistencia contable. La migración debe validar y normalizar los importes HNL a dos decimales, reportando valores rechazados o ajustados; no se debe reinterpretar el JSON local existente con otra unidad.

## 2. Superficies de ownership de Bryan

### Propiedad de este bloque

- Backend/API y configuración de runtime.
- Modelo relacional, migraciones, índices, claves foráneas y aislamiento por usuario.
- Registro, inicio/cierre de sesión, sesiones, recuperación y políticas de autenticación.
- Validación server-side de importes, fechas, recurrencias, propiedad e idempotencia.
- Panel administrativo separado, con autorización propia y auditoría; no existe todavía y requiere una decisión de alcance.
- Preparación de entorno de ensayo y despliegue Hostinger, después de verificar cuenta, plan, dominio y acceso.
- Adaptador de persistencia/migración que preserve el frontend actual hasta que el contrato sea aprobado.

### Límites

- No se modifican las ramas ni los módulos frontend de Owen en B01-L01.
- No se conectan datos ficticios a un servidor como si fueran datos reales.
- No se añaden credenciales, endpoints reales, proveedor de pagos ni integración bancaria en este loop.

## 3. Contrato API propuesto para revisión de Owen

Este contrato es una propuesta compartida, no una API existente. La versión inicial debe servirse bajo `/api/v1` y bajo el mismo origen del cliente cuando el hosting lo permita.

### Convenciones generales

- JSON UTF-8; `Content-Type: application/json`.
- Fechas de calendario como `YYYY-MM-DD` en la zona horaria declarada por el perfil.
- Marcas de tiempo como ISO-8601 UTC.
- El API persistente expresará los importes monetarios en HNL, como lempiras con dos decimales, por ejemplo `15000.00`; `currency` será `"HNL"`.
- Una conversión opcional a USD podrá acompañar una respuesta únicamente como dato informativo, con su contexto de referencia cuando esté disponible. Nunca reemplazará el importe HNL ni se usará como base de saldos, validaciones, pagos o persistencia.
- El servidor obtiene `userId` de la sesión autenticada. El cliente nunca puede elegir el titular mediante el body, query string o un ID ajeno.
- Las mutaciones que puedan repetirse aceptarán una clave de idempotencia; el alcance exacto se definirá antes de implementar pagos, aportes y confirmaciones.

### Respuesta exitosa

Objeto único:

```json
{
  "data": {},
  "meta": { "requestId": "opaque-request-id" }
}
```

Colección paginada:

```json
{
  "data": [],
  "meta": {
    "requestId": "opaque-request-id",
    "nextCursor": "opaque-cursor-or-null",
    "hasMore": false
  }
}
```

`limit` y los límites máximos quedan pendientes de decisión; no se fija un valor de hosting sin verificar capacidad real.

### Error común

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "El importe debe ser positivo.",
    "details": { "field": "amount" },
    "requestId": "opaque-request-id"
  }
}
```

Códigos mínimos propuestos: `AUTH_REQUIRED`, `AUTH_INVALID`, `FORBIDDEN`, `NOT_FOUND`, `VALIDATION_ERROR`, `CONFLICT`, `IDEMPOTENCY_CONFLICT`, `RATE_LIMITED` y `INTERNAL_ERROR`. No se deben mostrar stack traces ni detalles de conexión al cliente.

### Autenticación y sesión

| Método | Ruta | Propósito |
|---|---|---|
| POST | `/api/v1/auth/register` | Crear identidad después de definir verificación y política de correo. |
| POST | `/api/v1/auth/login` | Crear sesión segura. |
| POST | `/api/v1/auth/logout` | Invalidar la sesión actual. |
| GET | `/api/v1/auth/session` | Devolver la sesión y perfil mínimo del titular. |
| POST | `/api/v1/auth/password/forgot` | Solicitar recuperación sin revelar si la cuenta existe. |
| POST | `/api/v1/auth/password/reset` | Consumir un token de recuperación de un solo uso. |

El mecanismo concreto de sesión, proveedor de correo, requisitos de contraseña, bloqueo por intentos y expiración deben aprobarse antes de implementarse. No se debe asumir que el desbloqueo AES-GCM local puede convertirse en contraseña de cuenta.

### Recursos financieros

| Método | Ruta | Propósito |
|---|---|---|
| GET/PATCH | `/api/v1/me/profile` | Leer o cambiar zona horaria, preferencias y datos de perfil permitidos. |
| GET/POST | `/api/v1/movements` | Listar o crear movimientos del titular. |
| GET/PATCH/DELETE | `/api/v1/movements/{movementId}` | Consultar o modificar un movimiento propio. |
| GET/POST/PATCH | `/api/v1/budgets` | Gestionar presupuestos por categoría y periodo. |
| GET/POST/PATCH | `/api/v1/goals` | Gestionar metas de ahorro. |
| GET/POST | `/api/v1/goals/{goalId}/contributions` | Consultar o crear aportes pertenecientes a la misma meta y usuario. |
| GET/POST/PATCH | `/api/v1/reminders` | Gestionar obligaciones, tarjetas ficticias y fechas de aviso. |
| POST | `/api/v1/reminders/{reminderId}/payments` | Confirmar un pago una sola vez mediante idempotencia; no ejecutar débito bancario. |
| GET | `/api/v1/reports/daily` | Obtener resumen diario derivado de movimientos confirmados. |
| GET | `/api/v1/notifications` | Obtener avisos in-app según las preferencias del usuario. |
| PATCH | `/api/v1/notification-preferences` | Configurar avisos y horarios. |

Cada recurso debe devolver solamente registros del usuario autenticado. El asistente seguirá siendo determinístico y basado en datos autorizados; no se propone una API externa de IA en B01.

### Forma de recursos

Los nombres son contractuales a nivel conceptual; el esquema físico queda pendiente de diseño y aprobación.

```json
{
  "id": "opaque-id",
  "userId": "server-derived-user-id",
  "amount": 15000.00,
  "currency": "HNL",
  "date": "2026-11-18",
  "createdAt": "2026-11-18T18:00:00Z",
  "updatedAt": "2026-11-18T18:00:00Z"
}
```

Los campos específicos de movimiento, presupuesto, meta, aporte y recordatorio deben conservar la semántica ya visible en `src/finance.ts`, pero con validación server-side y sin copiar semillas ficticias al ambiente real. Si se ofrece un equivalente en USD, será opcional, informativo y separado del importe HNL autorizado.

## 4. Modelos y salvaguardas requeridos

### Modelos mínimos

1. Identidad y perfil.
2. Movimiento financiero.
3. Presupuesto por categoría y periodo.
4. Meta y aporte.
5. Recordatorio y confirmación de pago.
6. Preferencias de notificación.
7. Auditoría técnica mínima para cambios sensibles, sin guardar secretos ni datos de tarjeta completos.

Membresías, cobros, conexión bancaria y números completos de tarjeta quedan fuera del primer backend hasta contar con una autorización y diseño separados.

### Aislamiento y consistencia

- `userId` derivado exclusivamente de la sesión; nunca confiado al cliente.
- Claves foráneas que impidan asociar una meta, aporte, recordatorio o movimiento a otra cuenta.
- Índices por titular y fecha para listados y reportes.
- Transacciones para confirmación de pago, creación de movimiento derivado y actualización del estado del recordatorio.
- Clave de idempotencia para evitar doble confirmación y doble movimiento.
- Validación de importes finitos, positivos cuando corresponda, límites, fechas y recurrencias.
- Rechazo de acceso cruzado aunque se conozca un ID válido de otra cuenta.
- Rate limiting y registro de auditoría para autenticación y operaciones sensibles.

## 5. Migración y datos existentes

- No existe una base creada que pueda inspeccionarse desde este repositorio.
- El workspace local cifrado es un formato de demo del navegador, no una fuente autorizada de datos reales.
- No se importarán semillas `starter*` ni registros ficticios como cuentas reales.
- La migración futura debe comenzar con inventario, respaldo, conteos, validación de importes HNL con dos decimales y simulación sin escritura.
- Debe existir una ruta de exportación/backup y una prueba de restauración antes de aceptar datos reales.
- Los workspaces legados deben conservarse hasta que una migración aprobada confirme equivalencia; nunca se debe limpiar `localStorage` para ocultar incompatibilidades.
- La normalización de los importes actuales `number` a HNL con dos decimales necesita una regla aprobada de redondeo y un reporte de cada valor rechazado o ajustado. Cualquier equivalente USD debe permanecer informativo y no autoritativo.

## 6. Dependencias y orden de implementación

### Decisiones previas

- Cuenta, plan, dominio y ambiente de ensayo de Hostinger.
- Motor y versión de base de datos realmente disponibles.
- Proveedor y flujo de correo.
- Proveedor/mecanismo de autenticación y política de sesiones.
- Política de privacidad, retención, eliminación y soporte.
- Aprobación del contrato API y de la semántica de importes.

### Secuencia B01 posterior

1. Preparar un backend aislado con configuración por entorno y endpoint de salud sin secretos.
2. Definir migraciones versionadas y pruebas de restauración en una base de ensayo.
3. Implementar identidad, sesión, recuperación y aislamiento por cuenta.
4. Implementar movimientos y resumen; después presupuestos, metas, aportes y recordatorios.
5. Añadir idempotencia, auditoría, reportes y preferencias de avisos.
6. Preparar adaptador frontend compatible con el contrato, manteniendo la demo local durante la transición.
7. Ejecutar pruebas de dos cuentas, acceso cruzado, importes inválidos, reintentos, migración y restauración.
8. Revisar seguridad y privacidad; solo después preparar un despliegue de ensayo autorizado.

## 7. Coordinación con Owen

- Owen puede consumir este contrato como propuesta y señalar los campos que necesita para las pantallas, sin asumir que los endpoints ya existen.
- La UI debe distinguir estados `loading`, `unauthorized`, `forbidden`, `validation`, `conflict` y `server unavailable`.
- El cliente no debe enviar `userId` como autoridad, acceder directamente a MySQL ni interpretar el cifrado local como autenticación remota.
- Las pruebas frontend seguirán usando datos sintéticos hasta que exista un ambiente de ensayo separado y aprobado.
- Cualquier cambio en los shapes debe actualizar este documento y contar con revisión de ambos bloques.
- La integración debe entrar por un PR separado desde la rama de Bryan; no se modifican `feature/owen` ni su worktree.

## Próximo loop ejecutable

**B01-L02 — contrato y esqueleto de backend en ambiente local aislado**, condicionado a aprobar el proveedor de autenticación, motor/version de base de datos, precisión de los importes HNL y límites del ambiente de ensayo. No incluye credenciales, datos reales, producción ni merge a `main`.
