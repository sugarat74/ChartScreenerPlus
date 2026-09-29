---
name: sdd-apply
description: >-
  Implementa en una sola ejecución todas las tareas pendientes de una spec ya
  escrita en specs/<feature>/. Úsala cuando el usuario pida implementar una
  feature o una spec, aplicar sus tareas, o diga «Implementa la feature X»,
  «Aplica la spec de...», «Implementa todas las tareas de...» o «Ejecuta
  sdd-apply para...». No la uses para crear la spec ni para archivarla.
---

# sdd-apply

SDD (Spec Driven Development) significa que `specs/<feature>/` es la fuente de verdad. El código cumple esa spec. Si el código y la spec no caben juntos, se para y se enmienda la spec; no se improvisa.

La validación funcional la hace el usuario una sola vez, cuando todas las tareas pendientes ya se han implementado. Durante el apply no le pidas que pruebe tarea por tarea.

## Cuándo usarla

Actívala ante peticiones como:

- «Implementa la feature X.»
- «Aplica la spec de...»
- «Implementa todas las tareas de...»
- «Ejecuta sdd-apply para...»

## Archivos

Lee por completo, antes de tocar código:

- `specs/<feature>/spec.md`
- `specs/<feature>/design.md`
- `specs/<feature>/tasks.md`
- `AGENTS.md`, `CLAUDE.md`, `app/AGENTS.md`, `shared-test/AGENTS.md`
- `.agents/skills/android-architecture/SKILL.md`

Modifica solo el código que las tareas pendientes exigen y, al final, las casillas de `tasks.md` que correspondan. No edites `spec.md` ni `design.md` salvo que el usuario apruebe una enmienda.

## Procedimiento

1. Localiza `specs/<feature>/`. El nombre es kebab-case. Si el usuario da un título, normalízalo (minúsculas, sin acentos, espacios a `-`) y busca esa carpeta. Si no está, dilo y detente.
2. Comprueba que existan `spec.md`, `design.md` y `tasks.md`. Si falta alguno, detente y pide usar `sdd-propose`. No reconstruyas el archivo que falta.
   Motivo: implementar con una spec a medias obliga a inventar el contrato.
3. Lee los tres archivos enteros. No implementes a partir de un resumen parcial.
4. Comprueba que cada requisito (`R1`, `R2`, …) tenga al menos un escenario:
   **Escenario:** DADO <situación inicial> / CUANDO <acción> / ENTONCES <resultado>
   Si falta alguno, detente, cita el requisito y pide que lo reformulen. No inventes el escenario ni sigas.
   Motivo: sin escenario no hay forma de saber si la tarea está cumplida.
5. Comprueba que cada tarea pendiente cite uno o varios requisitos, como `(R2)` o `(R1, R3)`. Si alguna no cita ninguno, detente y pide corregir `tasks.md` con `sdd-propose` o con una edición acordada. No asignes requisitos por tu cuenta.
6. Lee las reglas y los patrones reales del repositorio (ficheros de la sección Archivos). El código nuevo sigue esa arquitectura y `design.md`. Si ambos chocan, trátalo como conflicto del paso 11.
   Motivo: las reglas del repo y `design.md` ya cerraron el cómo. Repetir el diseño en el código es el trabajo; rediseñarlo no.
7. Resume, en este orden: objetivo de la feature, requisitos, decisiones técnicas de `design.md`, tareas pendientes con su número y requisito, y comprobaciones que vas a ejecutar.
8. Detente y espera una confirmación explícita en un mensaje posterior («sí», «adelante», «confirmado» o equivalente). No edites código en el mismo turno del resumen.
   Motivo: el resumen sirve para cazar malentendidos antes de cambiar el proyecto. Confirmar en el mismo turno anula esa revisión.
9. Cuando haya confirmación, implementa todas las tareas que sigan en `[ ]`, en una sola ejecución, en el orden de `tasks.md`. Respeta las dependencias: no empieces una tarea cuya anterior necesaria no esté hecha.
   Motivo: el usuario valida la feature completa al final. Parar a mitad para preguntar por cada tarea rompe ese flujo y deja el código a medias sin necesidad.
10. Limítate a lo escrito. No añadas requisitos, pantallas, librerías, refactors ni tareas que no estén en la spec.
    Motivo: el trabajo extra no está aprobado y desvía el diff de lo que el usuario va a validar.
11. Si la spec y el código real chocan (falta una clase que el diseño da por hecha, una regla del repo prohíbe el diseño, un escenario es imposible tal cual):
    1. Deja de implementar.
    2. Explica el conflicto con ficheros concretos.
    3. Propón una enmienda concreta: qué archivo de la spec cambia y con qué texto.
    4. Espera la aprobación. No apliques la enmienda ni sigas codificando hasta entonces.
    Motivo: la spec es la fuente de verdad. Un desvío silencioso la deja falsa.
12. Ejecuta las comprobaciones técnicas disponibles. Lee antes `AGENTS.md` por si los comandos cambiaron. En este repositorio, y solo sobre lo que el cambio toque, usa este orden:
    1. `./gradlew --init-script gradle/init.gradle.kts --no-configuration-cache spotlessCheck`
    2. `./gradlew :app:testDebugUnitTest`
    3. `./gradlew :app:assembleDebug`
    4. Si cambias UI, navegación o DAO y hay emulador o dispositivo: `./gradlew :app:connectedDebugAndroidTest`
    Si Spotless falla solo por formato, aplica el objetivo `spotlessApply` con el mismo `--init-script` y `--no-configuration-cache`, y vuelve a pasar `spotlessCheck`.
    Motivo: una tarea no está terminada hasta que el proyecto demuestra que compila, cumple el formato y pasa los tests que cubren el cambio. En esta rama Spotless solo es válido con ese init script y sin configuration cache.
13. Marca `[x]` únicamente la tarea que está implementada y cubierta por comprobaciones que han pasado. Deja en `[ ]` las fallidas, las incompletas y las que no hayas podido verificar (por ejemplo, UI sin dispositivo para el test instrumentado). No marques el resto «para seguir».
    Motivo: la casilla afirma que la tarea está hecha y comprobada. Marcarla sin esa evidencia miente a quien archive la spec. La casilla no sustituye la validación funcional del usuario.
14. Si una comprobación falla por un defecto dentro de la spec, corrígelo y repítela. Si falla porque la spec no encaja, vuelve al paso 11. No des por buenas las tareas posteriores que dependan de la que falló.
15. Muestra al usuario, en este orden:
    - Resumen de lo implementado.
    - Tareas completadas (`[x]`) y pendientes (`[ ]`).
    - El diff (`git status` y `git diff`). No crees un commit.
    - Comprobaciones ejecutadas y su resultado.
    - Limitaciones: tests no lanzados, escenarios sin cobertura automática, conflictos abiertos.
16. Pide la validación funcional final de la feature, no de cada tarea. Indica qué escenarios de `spec.md` debe recorrer. Detente. Archivar es `sdd-archive`, y solo después de que el usuario confirme esa validación.

## Errores comunes

- Implementar sin `spec.md`, `design.md` y `tasks.md`, o rellenar a mano el archivo que falta.
- Empezar a editar código en el mismo turno del resumen, antes de la confirmación explícita.
- Inventar una librería, una capa, un esquema o un flujo que no está en `design.md` ni en las reglas del repositorio.
- Apartarte de la spec «porque en el código es más limpio» sin parar, proponer la enmienda y esperar.
- Ignorar el número y las dependencias de `tasks.md`, o añadir trabajo que ningún requisito pide.
- Marcar `[x]` al escribir el código, sin la compilación, el lint o los tests que el cambio permite ejecutar.
- Pedir al usuario que valide cada tarea por separado durante el apply. La validación funcional es una, al terminar todas las tareas.
