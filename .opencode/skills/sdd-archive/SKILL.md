---
name: sdd-archive
description: >-
  Verifica una spec terminada y la mueve de specs/<feature>/ a
  specs/_archive/<feature>/. Úsala cuando el usuario pida archivar o cerrar
  una spec, consolidar una feature terminada, o diga «Archiva la spec de...»,
  «Cierra la feature X», «Consolida la spec terminada de...» o «Ejecuta
  sdd-archive para...». No la uses si aún hay tareas sin marcar.
---

# sdd-archive

SDD (Spec Driven Development) termina cuando la spec está cumplida, comprobada y el usuario ha validado la feature. Archivar conserva ese contrato: no lo borra ni lo reescribe.

## Cuándo usarla

Actívala ante peticiones como:

- «Archiva la spec de...»
- «Cierra la feature X.»
- «Consolida la spec terminada de...»
- «Ejecuta sdd-archive para...»

## Archivos

Lee:

- `specs/<feature>/spec.md`
- `specs/<feature>/design.md`
- `specs/<feature>/tasks.md`

Busca evidencia en tests, en el diff de la feature y en resultados de comprobaciones ya escritos en la conversación o en esos archivos. Tras la confirmación del usuario, edita solo el inicio de `spec.md` y mueve la carpeta a `specs/_archive/<feature>/`.

## Procedimiento

1. Localiza `specs/<feature>/`. Si el usuario da un título, normalízalo a kebab-case (minúsculas, sin acentos, espacios a `-`) y busca esa carpeta. Si no existe, detente y dilo. Si ya está en `specs/_archive/<feature>/`, dilo y no la muevas otra vez.
2. Comprueba que existan `spec.md`, `design.md` y `tasks.md`. Si falta alguno, detente. No archives una spec incompleta ni recrees el archivo.
   Motivo: el archivo tiene que conservar el contrato entero. Una carpeta a medias no documenta lo que se construyó.
3. Revisa todas las tareas de `tasks.md`. Si alguna sigue en `[ ]`, lista número, texto y requisito de cada pendiente, y detén el archivado. No las marques `[x]` para poder cerrar.
   Motivo: una casilla abierta significa que esa parte no está implementada y comprobada. Archivarla la daría por hecha.
4. Revisa cada requisito de `spec.md`. Cada uno debe tener al menos un escenario:
   **Escenario:** DADO <situación inicial> / CUANDO <acción> / ENTONCES <resultado>
   Si falta un escenario, detente y pide reformularlo. No archives y no inventes el escenario.
   Motivo: sin escenario no puedes decir qué comportamiento quedó cerrado.
5. Para cada requisito, busca evidencia de cumplimiento: un test que cubra el escenario, un resultado de compilación o lint ya ejecutado, o evidencia escrita en la spec. Anota qué encontraste y qué no.
   Motivo: archivar sin mirar la evidencia convierte el cierre en un trámite. El usuario debe ver los huecos antes de confirmar.
6. Comprueba que la carpeta contenga exactamente esos tres archivos. Si hay otros, lista los extra y detente hasta que el usuario diga qué hacer con ellos.
   Motivo: el formato de la spec es cerrado. Mover ficheros de más los escondería dentro del archivo.
7. Muestra un resumen: requisitos, tareas (todas en `[x]`), evidencias encontradas y limitaciones (escenarios sin test automático, comprobaciones no ejecutadas).
8. Pide confirmación explícita de que el usuario hizo y aprobó la validación funcional final. Detente. No archives en el mismo turno del resumen.
   Motivo: las comprobaciones técnicas no sustituyen el uso real de la feature. Solo el usuario puede cerrar ese paso.
9. Si el usuario no confirma, o dice que la validación falló, no muevas nada. Si faltan correcciones, el camino es `sdd-apply` o una enmienda de la spec, no el archivo.
10. Cuando confirme, añade al principio de `spec.md` esta cabecera, con la fecha del día de la confirmación en formato `YYYY-MM-DD`. No cambies el resto del archivo.

```markdown
**Estado:** Archivada
**Fecha de cierre:** YYYY-MM-DD
**Validación funcional:** Confirmada por el usuario.

---
```

    Motivo: quien lea la spec dentro de `_archive/` tiene que ver, sin abrir la conversación, que se cerró, cuándo y que el usuario validó.

11. Crea `specs/_archive/` solo si no existe. Mueve `specs/<feature>/` a `specs/_archive/<feature>/`. Si el destino ya existe, detente y no fusiones carpetas.
12. Comprueba las dos rutas: `specs/<feature>/` ya no existe, y `specs/_archive/<feature>/` contiene `spec.md`, `design.md` y `tasks.md`, con la cabecera en `spec.md`.
13. Muestra el resumen final: ruta archivada, fecha de cierre, número de requisitos y de tareas, y las limitaciones que el usuario aceptó al confirmar.

## Errores comunes

- Archivar con alguna tarea todavía en `[ ]`, o marcar esas tareas como hechas durante el cierre para saltarse el bloqueo.
- Mover la carpeta sin una confirmación explícita de que el usuario aprobó la validación funcional final.
- Archivar sin leer los escenarios o sin mostrar qué evidencias hay y cuáles faltan.
- Borrar `specs/<feature>/` en lugar de moverla a `specs/_archive/<feature>/`, o reescribir `spec.md` al añadir la cabecera.
- Inventar un escenario o un resultado de test que no esté en el repositorio ni en la conversación para que el cierre parezca completo.
