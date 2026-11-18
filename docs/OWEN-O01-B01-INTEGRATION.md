# O01 — integración del cliente con B01

## Alcance implementado

El cliente conserva el espacio cifrado local como modo predeterminado. Para construir una vista de cuenta conectada contra la API de Bryan, definir `VITE_RUMBO_CLIENT_MODE=api` y compilar con la base del destino, por ejemplo `npm run build -- --base=/demos/rumbo-hn/`. La vista conectada no importa ni borra los registros del navegador local.

La primera vista conectada usa sesión y login por correo, perfil, resumen financiero autoritativo y movimientos paginados con alta, edición y baja. Los importes viajan como cadenas decimales HNL; la creación envía `Idempotency-Key` y reutiliza la misma clave si se reintenta un envío idéntico después de un fallo de red. El transporte conserva la cookie del mismo origen y agrega `X-CSRF-Token` desde la respuesta de sesión o login a las mutaciones. Los recursos de la PWA respetan la base del subdirectorio y la caché no intercepta la API.

El modo conectado es un recorrido inicial de O01, no un reemplazo de todas las pantallas locales. Presupuestos, metas, recordatorios, notificaciones y reportes todavía necesitan su integración de interfaz, pruebas de estados y validación contra cuentas aisladas. Registro y recuperación requieren las compuertas y el transporte de correo de B01; no se presentan como disponibles.

## Estado de la cookie en B01

Bryan corrigió `backend/src/Auth/SessionCookieManager.php` en `block/bryan-b01` para que las cookies de sesión y CSRF, incluida su limpieza, usen `RUMBO_BASE_PATH + /api/v1`. La combinación local de ambos bloques compila y pasa las pruebas frontend. Todavía no se ha demostrado que esa revisión esté desplegada en el laboratorio ni que login → sesión → mutación → logout funcione allí con cuentas sintéticas. Owen no modifica el backend en este bloque.

## Validación pendiente para la PR de integración

1. Verificar que la corrección de cookie de B01 está desplegada; disponer de dos cuentas sintéticas activas y confirmar el respaldo del demo y la base.
2. Construir ambos bloques juntos bajo la base del destino y ejecutar frontend y suite PHP.
3. Probar en navegador login, recarga, CSRF, movimientos, idempotencia, paginación, aislamiento de dos cuentas, errores y cierre de sesión.
4. Revisar las pantallas locales restantes antes de activar el modo API para todas las personas.

El endpoint de salud del demo responde, pero no demuestra por sí solo que las operaciones autenticadas funcionen.
