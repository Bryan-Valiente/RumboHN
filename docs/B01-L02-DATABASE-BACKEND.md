# RUMBO HN — B01-L02: base de datos y fundamento backend seguro

Estado del documento: implementación local preparada; la sección de ejecución remota se actualiza únicamente con evidencia observada.

## Verificación de Hostinger

- Base seleccionada: `u149165412_asesor`.
- Cuenta dedicada observada: `u149165412_rumbohn@127.0.0.1`.
- Motor: MariaDB `11.8.9-MariaDB-log`.
- Charset y collation: `utf8mb4` / `utf8mb4_unicode_ci`.
- Estado inicial observado en phpMyAdmin: cero tablas.
- Privilegios observados: `USAGE` global y `ALL PRIVILEGES` limitado a `u149165412_asesor.*`.
- Hostinger reporta acceso completo para esa base y cero conexiones externas autorizadas.
- Hosting: PHP 8.3 activo; `nd_pdo_mysql` activo; Node.js también está disponible.

No se leyó, imprimió, guardó ni modificó la contraseña de la base. No se abrió ni consultó ninguna tabla de Aura EV Perfumes u otro proyecto.

## Esquema inicial

La migración versionada `backend/migrations/001_initial_schema.sql` crea únicamente dentro de la base seleccionada:

1. `schema_migrations`: registro de versión aplicada.
2. `users`, `user_profiles`: identidad, perfil, onboarding, trial y saldo inicial.
3. `auth_sessions`, `password_reset_tokens`, `auth_attempts`: sesión, recuperación y fundamento de rate limiting.
4. `roles`, `user_roles`: autorización administrativa separada; no asigna administradores automáticamente.
5. `financial_transactions`: ingresos y gastos confirmados, planificados o anulados.
6. `budgets`: límites por categoría y periodo.
7. `savings_goals`, `goal_contributions`: metas y aportes con ownership compuesto.
8. `reminders`, `reminder_payments`: obligaciones, confirmación simulada, movimiento asociado y reversión.
9. `notification_preferences`, `notifications`: configuración y centro in-app.
10. `idempotency_keys`: exclusión de reintentos duplicados por usuario y operación.
11. `audit_logs`: trazabilidad técnica sin secretos.

Las vistas `v_user_transaction_totals`, `v_user_savings_totals`, `v_user_pending_obligations` y `v_user_financial_summary` calculan saldos sin almacenar reportes duplicados.

## Reglas financieras

- Moneda autoritativa: HNL.
- Persistencia: `DECIMAL(15,2)`.
- JSON: cadena decimal de dos posiciones, por ejemplo `"15000.00"`.
- Los importes son positivos; el tipo de operación determina ingreso o gasto.
- Los aportes reducen saldo disponible, pero no se duplican como gasto.
- Una conversión futura a USD será informativa y no se persiste en este esquema.

## Aislamiento y seguridad

- Claves foráneas compuestas obligan a que meta/aporte, recordatorio/pago y transacción/pago pertenezcan al mismo usuario.
- Las sesiones guardan solo el hash SHA-256 del token.
- Las contraseñas se preparan con Argon2id cuando está disponible.
- PDO desactiva prepared statements emulados y lanza excepciones controladas.
- La conexión falla si `RUMBO_DB_NAME`, `RUMBO_DB_ALLOWED_NAME` y `SELECT DATABASE()` no coinciden.
- No se habilita CORS abierto.
- Registro, login y recuperación están deliberadamente desactivados.
- Todas las rutas financieras autentican antes de llegar a un handler y permanecen sin implementar en este loop.
- La migración no contiene operaciones destructivas ni datos ficticios.

## Contrato para Owen

Los endpoints, envelopes y códigos de error de B01-L01 no cambian. La precisión se hace explícita: los campos monetarios JSON se intercambian como cadenas HNL con dos decimales. Owen no debe enviar `userId`, conectar el navegador a MariaDB ni asumir que los endpoints ya están desplegados. Estados mínimos: `AUTH_REQUIRED`, `AUTH_INVALID`, `AUTH_NOT_AVAILABLE`, `FORBIDDEN`, `VALIDATION_ERROR`, `CONFLICT`, `IDEMPOTENCY_CONFLICT`, `NOT_IMPLEMENTED`, `SERVICE_UNAVAILABLE` e `INTERNAL_ERROR`.

## Ejecución remota

- Evidencia inicial: base vacía y cuenta restringida a la base RUMBO confirmadas.
- Migración 001: aplicada correctamente mediante phpMyAdmin a `u149165412_asesor`.
- SHA-256 del SQL local y del archivo publicado usado para la ejecución: `03779a37109458b475d5e94a61f0ed2fe8eb8b19467980a44e2d8e562c50eeca`.
- Verificación posterior: 18 tablas base, 4 vistas, 20 columnas `DECIMAL(15,2)`, 19 claves foráneas, 39 restricciones `CHECK` y cero tablas fuera de InnoDB.
- `schema_migrations` contiene la versión `001` exactamente una vez.
- La base quedó sin usuarios, movimientos, aportes ni pagos.
- Prueba transaccional remota: `15000.00 HNL` se conservó exactamente, la vista calculó `15000.00`, un reintento con la misma clave mantuvo una sola fila idempotente y `ROLLBACK` devolvió usuarios, movimientos e idempotencia a cero.
- Backend PHP: no desplegado en este loop.

## Próximo loop

Implementar autenticación completa y repositorio de movimientos en un entorno de ensayo separado, incluyendo rate limiting, entrega de recuperación, pruebas de dos usuarios, acceso cruzado, expiración de sesión e idempotencia. Requiere una autorización separada antes de desplegar el backend.
