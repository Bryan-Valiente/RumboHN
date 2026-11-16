# O01-L01 — descubrimiento de la aplicación cliente

Estado: inventario verificado sobre `main` `a28aa290cf2b5c7064a72bae7f27d931e636e6a9`. Rama de trabajo: `block/owen-o01`. No se ha conectado una API. Actualización de contrato: B01 `6456195` establece importes API en lempiras HNL con dos decimales; prevalece sobre cualquier propuesta anterior de transportar centavos enteros.

## Base y límites

La aplicación es React + TypeScript + Vite. El `main` unificado conserva las pantallas previas de Owen y agregó el espacio personal local de Bryan: persistencia cifrada en el navegador, movimientos rápidos, plantillas, recordatorios recurrentes y un panel personal. No hay cliente HTTP, rutas de servidor, manifest PWA ni service worker. La base de datos creada por Bryan no es visible en este repositorio; su motor, esquema y credenciales deben venir de B01. No se asume que el borrador anterior de MySQL sea la configuración real.

Owen se encarga de `src/App.tsx`, `src/PersonalReminderModal.tsx`, los estilos de cliente y los adaptadores de API que se creen en `src/`. Bryan conserva esquema, autenticación de servidor, API, panel administrativo y despliegue. `src/finance.ts` y `src/persistence.ts` contienen reglas y formatos compartidos: cualquier cambio de su semántica financiera o migración se acuerda antes de editarlo.

## Inventario de flujos de cliente

| Área | Existe hoy | Dependencia para producto |
|---|---|---|
| Landing y navegación | Presentación pública, escenas ficticias, menú móvil y recorrido guiado. | Distinguir ejemplos públicos de cuentas reales. |
| Acceso y onboarding | Creación de espacio con contraseña local, desbloqueo y migración de formato local. | Registro, sesión, recuperación y estados de acceso definidos por Bryan. |
| Panel y día | Saldo, actividad, compromisos pendientes, acciones rápidas y revisión diaria. | Resumen y movimientos persistentes, fechas y estados de carga/error. |
| Movimientos | Alta, edición, baja, filtros, gastos rápidos y plantillas. | CRUD, orden, paginación, importes HNL de dos decimales e idempotencia. |
| Presupuestos | Límites y gasto derivado de movimientos. | CRUD por periodo/categoría y totales consistentes. |
| Metas | Creación, aportes y proyección de frecuencia. | Metas y aportes persistentes con operaciones atómicas. |
| Recordatorios | Alta, edición, baja, recurrencia, pago y reversión. | Contrato explícito para pago, generación de próxima cuota y reversión. |
| Informes y asistente | Cálculos y respuestas locales determinísticas. | Datos agregados del titular; definir qué funciones estarán disponibles en el MVP. |
| Perfil y avisos | Preferencias, bloqueo por inactividad, respaldo local y avisos internos. | Preferencias persistentes y manejo de sesión vencida; política de respaldo e importación. |
| Membresías | Prueba de siete días y activación simulada. | Los cobros quedan fuera del primer lanzamiento; definir si se oculta o deshabilita esta ruta. |

## Dependencia actual de almacenamiento local

`src/App.tsx` inicia desde `inspectWorkspaceStorage()`, conserva `PersistedWorkspace` en estado React y guarda cada cambio mediante `saveEncryptedWorkspace()` (`src/persistence.ts`). `WORKSPACE_STORAGE_KEY` contiene un sobre AES-GCM derivado de contraseña local; no equivale a una cuenta o sesión de servidor. El desbloqueo, la migración legado, el respaldo, el plazo de prueba y la suscripción simulada dependen de este formato. Al conectar la API, conservar el modo local y sus datos hasta que exista un flujo de importación con consentimiento, confirmación del servidor y recuperación ante fallo. No borrar el sobre local durante una transición incompleta.

El estado financiero usa importes `number` en lempiras e IDs numéricos creados en el cliente. El contrato B01 conserva HNL en lempiras con dos decimales en el API; el adaptador validará la precisión sin reinterpretar el JSON local como centavos. Las fechas de movimientos y vencimientos son cadenas locales `YYYY-MM-DD`; las marcas de creación son UTC. Los filtros, reportes y sugerencias hoy se calculan en el navegador.

## Hallazgos y prioridades

1. **P0 — semántica de saldo:** `PersonalDashboard` etiqueta `summary.balance` como «SALDO DISPONIBLE», aunque `calculateSummary` calcula `availableBalance = balance - allocatedSavings`. Corregir la etiqueta o el valor y alinear el contrato antes de conectar datos persistentes.
2. **P0 — pago de recordatorios:** `markReminderPaid()` crea un gasto y puede generar la siguiente cuota; `reverseReminderPayment()` revierte ambos efectos. `docs/PRODUCT-DATA-CONTRACT.md` aún afirma que marcar pagado no crea un movimiento. Bryan y Owen deben acordar una sola regla y una operación atómica e idempotente.
3. **P0 — acceso y datos:** el acceso actual es local, sin servidor ni recuperación por correo. El estado se guarda tras cambios mediante un efecto asíncrono; la migración a cuentas necesita prueba de fallo, reintento y preservación del respaldo.
4. **P1 — alcance comercial:** la prueba de siete días y la activación de planes son simuladas, mientras que pagos y membresías se aplazaron para después del primer lanzamiento. Definir el tratamiento de estas pantallas antes de sustituir textos de demo.
5. **P1 — interfaz API:** no hay `fetch` ni cliente HTTP. Las mutaciones están concentradas en `App.tsx`, lo que permite extraer un adaptador pero exige estados de carga, error, sesión vencida y reintento por operación.
6. **P1 — PWA:** `index.html` no declara manifest y no existe service worker. La estrategia de caché no debe guardar respuestas financieras ni sesiones sensibles sin diseño explícito.
7. **P2 — documentación:** README y la hoja de ruta aún describen datos únicamente en memoria; ya existe persistencia cifrada local. Actualizarlos en el loop que revise el lenguaje del producto.

## Loops ejecutables de O01

| Loop | Entrega | Criterio de aceptación |
|---|---|---|
| O01-L01 | Este inventario y las necesidades de API de `OWEN-O01-API-NEEDS.md`. | Base verificada, dependencias y discrepancias documentadas; sin alterar el cliente. |
| O01-L02 | Contrato B01 autorizado y adaptadores de transporte aislados del UI. | Rutas y envelopes documentados; validación HNL de dos decimales y de IDs; modo local conserva sus datos. Los campos específicos pendientes se cierran con Bryan antes de conectar pantallas. |
| O01-L03 | Lecturas de sesión, perfil, movimientos y resumen mediante el adaptador, con respuestas simuladas hasta que exista API. | Estados vacío/carga/error/sesión vencida comprobados; cambio entre modo local y API explícito; ninguna cuenta ficticia presentada como real. |
| O01-L04 | Mutaciones de movimientos y primer recorrido de integración con B02. | Altas, ediciones, bajas y reintentos no duplican registros; pruebas con dos usuarios y API real en entorno aislado antes de declarar integración completa. |

Siguiente loop ejecutable: O01-L02 **después de acordar** autenticación, recursos, errores, dinero, IDs y comportamiento de pago con Bryan. Mientras tanto se puede preparar una interfaz de repositorio cliente contra mocks, sin afirmar integración real.
