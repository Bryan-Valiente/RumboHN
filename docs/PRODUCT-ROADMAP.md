# Rumbo HN — transición a producto

Estado: propuesta de trabajo para revisión. Base: `feature/owen` en `a269b44`.

**Decisiones confirmadas:** el primer lanzamiento tendrá cuentas personales y datos financieros reales. Las membresías y los cobros quedan fuera del primer lanzamiento. El alojamiento elegido es Hostinger, en un plan Business o Cloud.

## Situación actual

La aplicación es un prototipo React/Vite con datos ficticios en memoria. `src/App.tsx` simula el acceso y mantiene el estado financiero con `useState`; `src/finance.ts` inicializa movimientos, metas y recordatorios de ejemplo y fija la fecha `DEMO_TODAY`. Los cambios se pierden al recargar. Las membresías y el asistente también son demostrativos. La compilación y las pruebas actuales validan parte de la lógica, pero no prueban cuentas, persistencia ni aislamiento entre usuarios.

La decisión de convertirlo en producto **no autoriza aún** introducir datos financieros reales en este prototipo. Antes de hacerlo, necesitamos una versión con identidad, almacenamiento, controles de acceso, recuperación y pruebas de seguridad verificables.

## Decisiones de alcance pendientes

1. Confirmar el alcance de los datos que podrá registrar una persona. Propuesta inicial: movimientos manuales, presupuestos, metas y recordatorios; sin credenciales bancarias ni conexión automática con bancos.
2. Designar quién administrará Hostinger, atenderá solicitudes de soporte y aprobará la política de retención y eliminación de datos.

## Ruta de entrega

### 1. Contrato del producto

Definir alcance de lanzamiento, datos admitidos, lenguaje de la interfaz y criterios de aceptación por módulo. Sustituir las promesas de «demo» solo cuando exista la capacidad real correspondiente. Diseñar estados vacío, carga, error y sesión vencida.

**Salida:** especificación aprobada, mapa de pantallas y lista de datos con dueño y finalidad.

### 2. Identidad y datos persistentes

Implementar una API Node.js y MySQL en Hostinger Business o Cloud, con un sistema de autenticación mantenido, sesiones, verificación de correo, recuperación de acceso y migraciones versionadas. Mantener datos ficticios en los entornos de prueba. La API debe validar importes, fechas, categorías y propiedad de cada registro. Cada consulta y mutación financiera debe quedar limitada al usuario derivado de la sesión; MySQL no sustituye estos controles de aplicación. Ninguna credencial de base de datos irá al navegador. Véase [arquitectura propuesta](HOSTINGER-ARCHITECTURE.md).

**Salida:** dos cuentas de prueba no pueden leer ni modificar datos entre sí; los registros sobreviven recarga y nuevo inicio de sesión; existe restauración de respaldo probada. Ningún flujo de acceso simulado se presenta como acceso real.

### 3. Flujo financiero

Usar centavos enteros para cálculos persistidos; definir semántica de saldo total, dinero disponible y aportes a metas. Completar movimientos, presupuestos, metas, recordatorios, informes y pruebas de casos límite. Evitar efectos duplicados por reintentos.

**Salida:** pruebas unitarias, de integración y de interfaz para altas, ediciones, bajas, filtros y conciliación de saldos.

### 4. Cierre de experiencia y lanzamiento

Completar navegación móvil, accesibilidad, seguridad de sesiones, mensajes de error, soporte, observabilidad sin exponer datos sensibles, respaldo, restauración y revisión de privacidad. Membresías y pagos quedan para una fase posterior. Ejecutar pruebas en Android/iOS físicos antes de invitar usuarios.

**Salida:** checklist de lanzamiento aprobado, incidentes críticos cerrados y despliegue autorizado por separado.

## Criterios de seguridad de referencia

Usaremos [OWASP ASVS](https://asvs.owasp.org/) como lista verificable de controles de aplicación y las [guías NIST SP 800-63-4](https://www.nist.gov/publications/nist-sp-800-63-4-digital-identity-guidelines) para decisiones de identidad. El nivel y los controles concretos se elegirán según los datos y las operaciones del lanzamiento.

Este documento no cambia la aplicación ni habilita un lanzamiento. Cada parte requiere implementación, pruebas, reporte y revisión antes de publicarse en `feature/owen`. El punto de partida es el prototipo actual; el primer punto de avance revisable es el contrato de datos y la arquitectura de Hostinger. Después avanzaremos por identidad y persistencia, flujo financiero y cierre de experiencia.
