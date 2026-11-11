# Rumbo HN — Tu dinero merece un rumbo

Prototipo visual, interactivo y responsive de una PWA de administración financiera personal para revisión de Bryan y su socio.

## Alcance

- Frontend local independiente con React, TypeScript y Vite.
- Datos ficticios en memoria; no hay base de datos, backend, autenticación real, pagos, bancos ni API de IA.
- Landing pública con identidad hondureña sutil y explorador de tres escenas.
- Registro/inicio de sesión simulados y configuración guiada para principiantes.
- Panel con resumen, movimientos, presupuestos, metas, asistente, informes, membresías, perfil, privacidad y ayuda.
- Modo claro/oscuro, responsive para 360–430 px y escritorio, movimiento reducido, foco visible y formularios etiquetados.
- Estado financiero centralizado en memoria: saldo inicial + ingresos − gastos, con presupuestos y metas derivados del mismo conjunto de datos.
- Alta, edición, eliminación y filtrado de movimientos por tipo, categoría y periodo.
- Presupuestos editables con avisos de aproximación al límite y excedente; nunca bloquean un gasto ficticio.
- Metas creadas desde la interfaz y aportes simulados que actualizan el progreso sin duplicar gastos.
- Informes recalculados con categorías, balance, uso de presupuestos y progreso de metas.
- Asistente de demostración determinístico con respuestas basadas en el estado financiero actual.
- Guía rápida de cinco pasos y acción “Restablecer demostración” con confirmación.

## LOOP-003 — planificación y experiencia diaria

- Registro de dinero recibido con fuente, recurrencia y separación explícita entre ingresos confirmados e ingresos futuros planificados.
- Calculador de metas con frecuencias diaria, semanal, cada 14 días, dos veces al mes y mensual; usa fechas locales, centavos enteros, redondeo hacia arriba y ajuste del último aporte.
- Módulo “Mis recordatorios” para obligaciones y tarjetas ficticias, con corte y vencimiento independientes; marcar como pagado no crea un gasto.
- Centro de avisos dentro de la demo, preferencias configurables, horario de mañana/noche y zona horaria declarada; no solicita permisos de navegador ni promete push con la app cerrada.
- Briefing “Buenos días”, revisión “¿Cómo te fue con tu dinero hoy?”, pulso financiero y acciones rápidas para móvil.
- Onboarding con múltiples objetivos y responsive endurecido en 320, 360, 375, 390, 430, 768, 1024 y 1440 px.
- Toda la simulación continúa siendo ficticia, determinística y en memoria. No se agregaron servicios externos.

## Dirección visual fijada

La investigación viva de Refero fue intentada, pero la cuenta conectada respondió `NO_SUBSCRIPTION`. Se aplicó el workflow de craft local de la skill `refero-design` y se fijó este lock:

- Base: producto fintech claro, editorial-utilitario, con aire y tensión visual; no se usa indigo/violeta como acento principal.
- Preservar: azul petróleo como estructura/confianza, verde como avance/éxito, amarillo solar solo para acciones e insights, bordes finos y tarjetas reservadas para interacción.
- Detalle memorable: la “línea de rumbo” que conecta saldo, metas y actividad; la demo habla en lempiras y usa expresiones hondureñas sin convertir la interfaz en propaganda.
- Tipografía: Manrope para jerarquía y DM Sans/DM Mono para lectura operativa y etiquetas.
- Rechazos: nombre ROTA, símbolos patrios exagerados, datos reales, claims de integraciones y patrones de dashboard genérico con tarjetas innecesarias.

## Ejecutar

```bash
npm install
npm run dev
```

Abrir la dirección que muestre Vite, normalmente:

`http://127.0.0.1:5173/`

Para generar build de producción local:

```bash
npm run build
```

## QA realizado

- `npm run build`: PASS (`tsc -b` + `vite build`).
- Dependencias auditadas durante instalación: 0 vulnerabilidades reportadas.
- Verificación HTTP local: PASS en el servidor Vite activo.
- Verificación de compilación posterior a LOOP-002: PASS.
- Pruebas funcionales automatizadas: no hay runner de tests configurado; la lógica está separada en `src/finance.ts` y `src/assistant.ts` para revisión dirigida.
- QA visual con Computer Use: bloqueado al intentar abrir `localhost:5173` porque el navegador automatizado devolvió `ERR_CONNECTION_TIMED_OUT` y la capa de Windows no pudo determinar la URL activa con suficiente confianza. No se forzó una ventana existente.

LOOP-003 agregó:

- `npm run build`: PASS posterior a los cambios.
- Preview Vite temporal en `http://127.0.0.1:5175/`: PASS durante la validación interactiva; se detuvo al terminar la QA.
- Verificación interactiva: landing, onboarding, dashboard, acciones rápidas, registro de ingreso, metas, recordatorios, tarjeta, avisos y revisión diaria: PASS.
- Responsive: PASS sin overflow horizontal en los ocho anchos solicitados.
- `git diff --check`: PASS.
- Runner automatizado de tests: no configurado; no se marcaron pruebas unitarias como PASS.

## Flujo colaborativo

- `main` es la rama estable y se actualiza mediante pull requests.
- Bryan trabaja en `feature/bryan`.
- Owen trabaja en `feature/owen`.
- Cada cambio debe desarrollarse en una rama propia, validarse localmente y proponerse mediante un pull request hacia `main`.
- Antes de comenzar una tarea, cada colaborador debe actualizar su rama desde el `main` más reciente usando una estrategia segura y resolver conflictos antes de solicitar revisión.
- No se deben hacer pushes directos a `main`, ni fusionar cambios incompletos o con el build fallando.

## Limitaciones de la demostración

RUMBO HN es una demostración frontend local. No incluye backend, base de datos, autenticación real, API de IA, conexiones bancarias, pagos ni datos financieros reales. Todos los registros y respuestas son ficticios y viven únicamente en memoria del navegador.

## Pendientes de aprobación

- Nombre final de marca y decisión comercial sobre los planes de US$5, US$10 y US$15.
- Beneficios definitivos de cada membresía.
- Copys finales, tono de voz y alcance del asistente.
- Fuente de identidad visual final (logo, colores oficiales y eventuales ilustraciones).
- QA manual en dispositivos Android/iOS físicos antes de considerar cualquier paso posterior.
