---
name: web-to-mobile
description: Evalúa y planifica la extensión de una aplicación web a Android e iOS, y guía su implementación incremental cuando se solicita. Compara React Native con Expo y alternativas, define repositorio Git, API compartida, autenticación y desarrollo paralelo. Úsala para llevar un producto web a móvil; no para un ajuste responsive o un despliegue web rutinario.
---

# Web a Android e iOS

Extiende el producto existente con clientes móviles que puedan evolucionar junto
a la web. Trabaja sobre su arquitectura y contratos reales. No presupongas que
sus componentes web se pueden ejecutar en React Native.

## Elegir el alcance

- **Evaluar / preparar la conversión:** inspeccionar y documentar decisiones,
  alternativas, incertidumbres, pruebas exploratorias y esfuerzo. Crear una
  skill o estudiar la arquitectura no autoriza implementar las aplicaciones.
- **Planificar:** convertir la decisión en entregas pequeñas con dependencias,
  criterios verificables y contratos. Usar el mecanismo de specs del proyecto.
- **Implementar:** ejecutar el incremento solicitado sobre un baseline verificado;
  actualizar contratos, clientes afectados, pruebas y documentación en el mismo
  cambio cuando sea necesario. Respetar autorizaciones ya otorgadas.

Publicar en tiendas, activar servicios de pago o cambiar producción requiere
autorización que cubra esa acción. Preparar artefactos y verificaciones locales
antes de solicitarla. No introducir aprobaciones adicionales para trabajo ya
autorizado. Desarrollo paralelo no implica crear subagentes automáticamente.

## Descubrir lo que se puede reutilizar

Leer AGENTS, progreso, backlog, arquitectura, permisos, diseño, manifests,
lockfiles, CI y rutas/controladores de la API. Buscar con `rg`; excluir dependencias,
compilados y worktrees ajenos. No leer secretos para inventariar configuración.

Identificar:

- Lógica del servidor y propiedad de la base de datos; endpoints públicos y privados.
- Acoplamiento a DOM, cookies, CSRF, almacenamiento del navegador y rutas relativas.
- Autenticación, revocación, auditoría de accesos y autorización por recurso.
- Tipos, serialización, traducciones y tokens visuales realmente independientes de UI.
- Gráficos, gestos, enlaces profundos y otras dependencias que necesitan validación nativa.
- Entorno de desarrollo, dispositivos de prueba y disponibilidad de macOS/compilación remota.

Distinguir código observado, evidencia histórica y estado de producción. Consultar
fuentes oficiales actuales para compatibilidad Expo/React Native/React/Node,
toolchain, bibliotecas y requisitos de distribución. No fijar versiones por memoria.

## Decidir arquitectura y Git

Leer [references/architecture.md](references/architecture.md) para comparar
frameworks, definir límites de reutilización, autenticación y organización de Git.
Usar [references/sources.md](references/sources.md) como punto de partida para
verificar documentación; su fecha de consulta no garantiza vigencia futura.

Entregar una recomendación motivada con:

1. Tecnología móvil y alternativa descartada con su coste real de adaptación.
2. Árbol del repositorio, dirección de dependencias y tratamiento de lockfiles.
3. Contrato HTTP compartido, auth web/móvil, permisos y compatibilidad con apps antiguas.
4. Estrategia del gráfico y prueba exploratoria que confirme su viabilidad.
5. Trabajo paralelo por responsabilidades, integración, CI y releases independientes.
6. Entregas, estimaciones con supuestos y decisiones pendientes de evidencia.

Un monorepo es una opción preferente cuando un mismo equipo mantiene API, web y
móvil y necesita cambios atómicos. Evaluar repos separados si existen límites de
acceso/equipo o ciclos independientes que lo justifiquen. No mover Laravel ni
reescribir la web solo para adoptar un árbol de carpetas convencional.

## Aplicación a Chartiko

Si el repositorio es Chartiko, leer `docs/mobile/architecture-plan.md` cuando exista;
es la propuesta inicial del 2026-10-08, no evidencia de una app ya implementada.
Contrastar sus hallazgos con el código actual antes de ejecutarla.

- Laravel es el único propietario de PostgreSQL. El engine Python permanece privado;
  clientes móviles consumen HTTPS/JSON de Laravel. Una cuenta y sus recursos se
  comparten entre web y móvil sin duplicar bases ni usuarios.
- Preservar `frontend/`, el Laravel raíz y `engine/`; proponer `mobile/` y paquetes
  TypeScript puros. El cambio de lockfiles/workspaces necesita una entrega propia
  que actualice las restricciones actuales y verifique el deploy web.
- La web usa sesión Sanctum + CSRF. Verificar `requireStatefulSession`, `HasApiTokens`,
  rutas y pruebas antes de diseñar auth móvil; tener Sanctum instalado no basta.
- Mantener Screener, Candidate, Instrument, Signal, Saved Screener y Watchlist.
  El móvil no recalcula Signals ni convierte el pipeline a intradía.
- Conservar español/inglés y catálogos; extraer solo contenido independiente de DOM.
  Las etiquetas EOD siguen limitadas a Admin. `alphapulse/` es solo referencia visual.
- La UI de Admin queda inicialmente en web como propuesta de alcance; no confundir
  ocultar pantallas móviles con impedir su acceso en la API. La revocación de
  sesiones web y la de tokens móviles deben distinguirse explícitamente.

La solicitud explícita de preparar móvil permite estudiarlo aunque sea posterior
al MVP original. No amplía automáticamente el alcance a IA, pagos, alertas u offline.

## Verificar y entregar

Leer [references/verification.md](references/verification.md) al preparar criterios
de aceptación o validar un incremento. Ejecutar la verificación estándar cuando
se cambia producto/toolchain; para planificación o skill solamente, validar sus
archivos y enlaces y registrar que no se ha verificado una aplicación móvil.

Guardar decisiones sustanciales en documentación del repositorio, y actualizar
progreso/backlog conforme a su ciclo de estados. No declarar aceptado sin validación
independiente. Informar archivos creados, decisión recomendada, evidencias ejecutadas,
limitaciones y siguiente incremento. No prometer reutilización total, plazos de
revisión de tiendas ni soporte iOS validado desde un build exclusivamente Android.
