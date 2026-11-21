# AGENTS.md — RUMBO HN

Estas instrucciones aplican al repositorio independiente `Bryan-Valiente/RumboHN`.

## Alcance y ramas

- Trabaja únicamente en la tarea autorizada y conserva cambios no relacionados.
- Ramas permanentes: `main`, `block/bryan-b01` y `block/owen-o01`. Reutilizarlas; no crear ramas por tarea.
- Bryan trabaja en `block/bryan-b01`; Owen en `block/owen-o01`. Ambos conservan acceso al proyecto completo y pueden realizar commits ordinarios dentro del scope asignado sin aprobación mutua.
- No hagas commits directos en `main` ni fusiones automáticamente ramas hacia `main`.
- Desplegar únicamente cuando exista autorización explícita. El laboratorio RUMBO es `https://valienteinversionesgroup.com/demos/rumbo-hn/`; preservar otros proyectos y bases.
- No uses `git reset --hard`, force-push ni reescritura de historial.
- No elimines trabajo inédito, archivos no relacionados, secretos ni credenciales.

## Punto de finalización de una tarea autorizada

Cuando una tarea de desarrollo autorizada haya terminado:

1. Confirma la rama actual y que pertenece al desarrollador autenticado.
2. Revisa `git status`, `git diff` y los archivos staged.
3. Ejecuta `npm run test:run`.
4. Si cambió código de la aplicación, ejecuta también `npm run build`.
5. Ejecuta `npm audit --audit-level=high` cuando la tarea modifique dependencias o configuración del proyecto.
6. Comprueba `git diff --check` y busca secretos o respaldos no intencionados.
7. Staged únicamente los archivos de la tarea.
8. Crea un commit convencional descriptivo.
9. Verifica el SHA y el contenido del commit.
10. Después de validar, publica en la rama permanente del desarrollador si el remoto sigue siendo seguro.

## Integración y despliegue

- Iniciar cada bloque con las ramas sincronizadas desde el último `main` aprobado, mediante fast-forward o merge no destructivo.
- Integrar Owen en la rama existente de Bryan, resolver conflictos y validar el resultado.
- Abrir un único PR de `block/bryan-b01` a `main`; respetar protecciones y revisiones obligatorias.
- Conservar ambas ramas permanentes después del merge; no habilitar su eliminación automática.
- El despliegue Hostinger es manual: compilar el SHA aprobado de main con base `/demos/rumbo-hn/`, respaldar archivos/configuración privados, publicar solo RUMBO y verificar el runtime en vivo.
- Un merge no despliega Hostinger. Registrar el SHA efectivamente publicado y sincronizar ambas ramas después de verificar.
- Coordinar archivos compartidos mediante la especificación del bloque; conservar commits y autoría.

Los commits y pushes se realizan en checkpoints de finalización de tareas, nunca por cada guardado de archivo o cambio intermedio.

## Push seguro

- Antes de publicar, ejecuta `git fetch origin` y confirma que la rama remota no avanzó inesperadamente.
- Si el remoto avanzó, conserva ambos trabajos y reconcilia mediante merge o rebase explícitamente revisado; nunca fuerces el push.
- Si falla la red, conserva el commit local y reporta `COMMITTED_NOT_PUSHED`; no afirmes que el push tuvo éxito.
- Un push exitoso no autoriza un merge en `main`. Toda integración requiere pull request, protección y aprobación independiente.

## Identidad y seguridad

- Usa la autenticación propia del desarrollador. Nunca solicites, pegues ni registres contraseñas, tokens u OTP.
- No incluyas credenciales, datos reales de clientes, pagos ni respaldos generados en commits.
- Si hay conflicto, permisos insuficientes o alcance ambiguo, detén la mutación y reporta el bloqueo.
