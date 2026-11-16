# B01-L03 — autenticación, aislamiento y API financiera

## Estado verificable

Implementado en `block/bryan-b01` como backend local no desplegado. No se modificó la migración `001`, la base Hostinger, el frontend de Owen, `main` ni infraestructura de producción.

## Autenticación

- Endpoints implementados: registro, login, logout, sesión, solicitud de recuperación y restablecimiento.
- Contraseñas: Argon2id cuando el runtime lo soporta; mínimo 12 y máximo 128 bytes, con tres clases de caracteres.
- Sesiones: token aleatorio de 256 bits; solo su hash SHA-256 se persiste; expiración y revocación explícitas.
- Navegador: cookie de sesión `HttpOnly`, `SameSite=Strict`; `Secure` obligatorio en producción. La cookie CSRF no es `HttpOnly` para permitir el patrón cookie + encabezado de doble envío firmado.
- CSRF: toda mutación autenticada mediante cookie exige `X-CSRF-Token` igual a la cookie y al valor HMAC derivado de la sesión. Un bearer token explícito no depende de cookies y no usa CSRF.
- Origen: si existe `Origin`, debe coincidir exactamente con `RUMBO_ALLOWED_ORIGIN`.
- Rate limiting: intentos fallidos persistentes por hash HMAC de identidad e IP; respuestas genéricas para evitar enumeración.
- Recuperación: token aleatorio, hash en base, expiración, un solo uso y revocación de sesiones después del cambio.

### Compuertas de activación

Todas parten en `false` en `.env.example`:

- `RUMBO_LOGIN_ENABLED`.
- `RUMBO_REGISTRATION_ENABLED` y `RUMBO_ACCOUNT_VERIFICATION_READY` deben estar ambas listas para registro.
- `RUMBO_PASSWORD_RECOVERY_ENABLED` requiere además un transporte real de entrega. El código desplegable incluye únicamente un transporte no disponible; el fake vive en pruebas.

El registro crea cuentas `pending`. `AccountActivator` es un servicio interno sin ruta pública: cambia una cuenta elegible a `active` e inicia el trial exactamente una vez por siete días. No existe autorregistro administrativo ni endpoint de cambio de rol, estado o fechas de trial.

## Perfil y autorización

`GET/PATCH /api/v1/me/profile` permite nombre, zona horaria, locale y datos de onboarding permitidos. El usuario no puede cambiar estado, roles ni trial. El saldo inicial se bloquea al completar onboarding.

`AdminAuthorizer` exige el rol `admin` obtenido server-side desde `user_roles`. El `Router` admite rutas con acceso `admin`; no se añadió todavía una ruta ni UI administrativa.

## Movimientos

Endpoints implementados:

- `GET /api/v1/movements?limit=25&cursor=...`.
- `POST /api/v1/movements` con encabezado `Idempotency-Key`.
- `GET/PATCH/DELETE /api/v1/movements/{movementId}`.

Las consultas siempre incluyen `user_id` derivado de la sesión. Un ID de otra cuenta se trata como no encontrado. La paginación es keyset por fecha e ID, con cursor firmado. El borrado es lógico. Los movimientos creados por un pago de recordatorio no pueden editarse ni borrarse desde el CRUD genérico.

Los importes son cadenas HNL con dos decimales. La persistencia sigue usando `DECIMAL(15,2)`; números JSON, negativos, cero, notación científica y más de dos decimales se rechazan.

## Resumen financiero

`GET /api/v1/financial-summary` consume `v_user_financial_summary` y devuelve cadenas HNL para ingresos confirmados, egresos confirmados, saldo total, ahorro asignado, saldo disponible, obligaciones pendientes y saldo proyectado. Movimientos planificados no afectan el saldo confirmado. Un recordatorio pagado deja de formar parte de obligaciones antes de que su movimiento confirmado se deduzca, evitando doble descuento.

## Validación local

La suite `backend/tests/run.php` usa únicamente cuentas y movimientos sintéticos en una SQLite efímera. Cubre registro y hash, activación/trial, login/logout, expiración y revocación, rate limiting, recuperación fake, CSRF, roles, dos usuarios, acceso cruzado, CRUD, idempotencia, paginación y cálculo del resumen.

`backend/tests/mariadb_integration.php` valida los repositorios SQL solamente cuando el ambiente declara `RUMBO_APP_ENV=test`, usa loopback y la base termina en `_test`. En B01-L03 se ejecutó contra una MariaDB 10.4 local efímera: la migración aplicó 25 sentencias y pasaron cinco grupos de integración (dos titulares, sesión/rol, idempotencia/precisión/aislamiento, vista financiera y actualización/borrado lógico). La base y el servidor efímeros se eliminaron al terminar.

SQLite y MariaDB 10.4 local verifican la lógica y la compatibilidad SQL básica, pero no sustituyen una prueba posterior con la MariaDB 11.8 exacta en staging. Nunca se debe usar la base Hostinger productiva como banco de prueba.

## Próxima frontera

B01-L04 debe preparar un staging privado y reversible: transporte real de correo/verificación, secretos fuera de Git, TLS, rutas de despliegue, migración forward-only si fuera necesaria y pruebas HTTP/PDO contra una MariaDB aislada antes de habilitar cualquier compuerta.
