---
name: sdd-propose
description: >-
  Crea la spec de una feature nueva en specs/<nombre-feature>/ con spec.md,
  design.md y tasks.md, sin escribir código. Úsala cuando el usuario pida
  crear, proponer o definir una spec, iniciar el SDD de una feature, o diga
  «Crea una spec para...», «Propón la spec de...», «Define la feature...»,
  «Inicia el SDD de...» o «Ejecuta sdd-propose para...».
---

# sdd-propose

SDD (Spec Driven Development) significa que primero se escribe un contrato y después se implementa. Esta skill solo crea ese contrato. El código de la aplicación se escribe en otro paso, con `sdd-apply`.

## Cuándo usarla

Actívala ante peticiones como:

- «Crea una spec para...»
- «Propón la spec de...»
- «Define la feature...»
- «Inicia el SDD de...»
- «Ejecuta sdd-propose para...»

## Archivos

Lee, antes de escribir la spec:

- `AGENTS.md` y `CLAUDE.md`
- `app/AGENTS.md` y `shared-test/AGENTS.md`
- `.agents/skills/android-architecture/SKILL.md`

Crea exactamente estos tres archivos:

- `specs/<nombre-feature>/spec.md`
- `specs/<nombre-feature>/design.md`
- `specs/<nombre-feature>/tasks.md`

No crees otros archivos. No modifiques código, tests, Gradle ni recursos de la app.

## Procedimiento

1. Identifica el nombre de la feature y normalízalo a kebab-case: minúsculas, sin acentos, espacios y guiones bajos convertidos en `-`, sin más caracteres. Ejemplo: «Autenticación biométrica» queda en `autenticacion-biometrica`.
   Motivo: el nombre de la carpeta es la identidad de la spec; dos grafías distintas crearían dos specs de la misma feature.

2. Comprueba que no exista `specs/<nombre-feature>/` ni `specs/_archive/<nombre-feature>/`. Si alguna existe, detente y dilo. No sobrescribas.
   Motivo: una spec ya escrita es un contrato; reescribirla borra decisiones que el usuario pudo haber aprobado.

3. Lee los ficheros de la sección Archivos. Anota la arquitectura real, los comandos de verificación y las restricciones del repositorio.
   Motivo: `design.md` tiene que encajar en este proyecto. Si no lees esas reglas, propondrás capas, librerías o comandos que aquí no existen.

4. Revisa si el mensaje del usuario ya dice el motivo, el alcance (qué entra y qué queda fuera) y las restricciones. Si falta alguno de esos tres, detente y haz una entrevista breve: como máximo una pregunta por cada hueco. No crees archivos en ese turno.
   Motivo: sin motivo, alcance o restricciones la spec inventa el producto. Preguntar es más barato que corregir tres archivos.

5. Redacta los requisitos solo con lo que el usuario pidió y con las restricciones ya escritas en el repositorio. Numéralos `R1`, `R2`, etc. Cada uno usa `DEBE`, `PUEDE` o `NO DEBE`. Cada uno lleva al menos un escenario verificable, con este texto:
   **Escenario:** DADO <situación inicial> / CUANDO <acción> / ENTONCES <resultado>
   Si un requisito no puede expresarse así, detente, muestra el requisito y pide al usuario que lo reformule. No inventes el escenario.
   Motivo: un requisito sin escenario no se puede comprobar. Un escenario inventado verifica lo que imaginaste, no lo que el usuario quiere.

6. Crea `spec.md`, `design.md` y `tasks.md` con las plantillas de abajo. En `design.md`, cierra solo decisiones que salgan del usuario o de las reglas del repositorio. Si una sección no aplica, escribe `No aplica` y una frase que lo explique. No dejes secciones vacías y no añadas librerías ni cambios de base de datos por tu cuenta.
   Motivo: `design.md` es el contrato técnico. Una decisión inventada se convierte después en obligación para `sdd-apply`.

7. Relee `tasks.md`. Cada tarea es pequeña, empieza en `[ ]`, tiene número correlativo desde 1 y cita el requisito que cubre, por ejemplo `(R2)` o `(R1, R3)`. Una tarea que depende de otra va después. Si el orden no respeta una dependencia, reordénalas antes de enseñar la propuesta.
   Motivo: `sdd-apply` ejecuta las tareas en el orden escrito. Un orden invertido hace fallar la implementación o obliga a improvisar.

8. Muestra un resumen con: ruta de la spec, por qué, requisitos, decisiones de `design.md` y lista numerada de tareas. Pega las rutas de los tres archivos.
9. Pide al usuario que revise y apruebe la spec, o que diga qué hay que cambiar. Detente. No escribas código de la aplicación en este turno ni en los siguientes de esta skill.
   Motivo: la aprobación de la spec no es permiso para implementar. Implementar es `sdd-apply`, que tiene su propia confirmación.

10. Si el usuario pide cambios, edita solo los tres archivos de la spec, repite el resumen y vuelve a pedir aprobación. Si la aprueba, confirma que la spec queda lista y que el siguiente paso es `sdd-apply`. Ahí termina esta skill.

## Plantilla de `spec.md`

```markdown
# <Nombre de la feature>

## Por qué

<Para qué existe esta feature y qué problema resuelve.>

## Requisitos

### R1. <Título corto>

<La feature> DEBE <comportamiento observable>.

**Escenario:** DADO <situación inicial> / CUANDO <acción> / ENTONCES <resultado>

### R2. <Título corto>

<La feature> NO DEBE <comportamiento prohibido>.

**Escenario:** DADO <situación inicial> / CUANDO <acción> / ENTONCES <resultado>
```

Añade un requisito por cada comportamiento acordado. `PUEDE` se reserva para lo opcional que el usuario aceptó.

## Plantilla de `design.md`

```markdown
# Diseño: <nombre-feature>

## Arquitectura

<Dónde vive el cambio, según las reglas del repositorio. Capas, clases y ficheros previstos.>

## Librerías

<Librerías ya presentes que se usarán, o «No aplica» si no hace falta ninguna nueva.>

## Restricciones de seguridad

<Límites concretos, o «No aplica» y por qué.>

## Cambios de base de datos

<Esquema, migración o fuente de datos, o «No aplica» y por qué.>

## Decisiones de implementación

<Decisiones cerradas que el implementador no puede reinventar: navegación, estado, errores, tests previstos.>
```

## Plantilla de `tasks.md`

```markdown
# Tareas: <nombre-feature>

- [ ] 1. <Tarea pequeña que no depende de otra> (R1)
- [ ] 2. <Tarea que depende de la 1> (R1, R2)
```

## Errores comunes

- Aceptar un requisito sin escenario `DADO / CUANDO / ENTONCES`. Sin escenario, nadie puede decir si la feature está hecha.
- Escribir código de la app, tests o recursos mientras se «crea la spec». Esta skill termina en tres archivos Markdown.
- Rellenar `design.md` con librerías, capas o tablas que el usuario no pidió y el repositorio no usa. Si falta una decisión, se pregunta; no se inventa.
- Publicar tareas sin requisito `(R*)`, desordenadas o tan grandes que mezclan varios cambios. `sdd-apply` las ejecutará tal cual.
- Sobrescribir `specs/<nombre-feature>/` o reutilizar una carpeta archivada. Hay que detenerse y avisar.
