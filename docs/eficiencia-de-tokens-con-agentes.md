# Eficiencia de tokens con agentes de IA

Guía práctica para que el coste en tokens de cada feature no crezca a medida que avanza un proyecto. Sale de lo que pasó en Chartiko y vale para cualquier proyecto que use un harness (`AGENTS.md` + `feature_list.json` + `PROGRESS.md` + `init`).

## 1. El problema: el coste de arranque crece con el historial

Cada sesión de un agente empieza en frío y lee lo que `AGENTS.md` le manda leer. Si eso incluye archivos que crecen con cada feature, **cada feature nueva encarece todas las siguientes**, aunque el código no se haya vuelto más complejo.

Así estaba Chartiko el 2026-10-25, tras 72 features (64 `accepted`):

| Archivo | Tamaño | Tokens aprox. | Qué lo inflaba |
|---|---|---|---|
| `feature_list.json` | 320 KB | ~85k | El campo `evidence` de features ya cerradas: 200 KB de los 320 |
| `PROGRESS.md` | 262 KB | ~70k | 98 secciones de diario de sesión que nunca se recortaban |
| **Total leído al arrancar** | **~580 KB** | **~150k** | El paso de arranque decía *"read PROGRESS.md and feature_list.json for the full evidence"* |

Mientras tanto, `scripts/harness-context.ps1`, que ya resume rama, cambios locales, features listas, cola de validación y riesgos, devolvía **menos de 1 KB**.

Después de archivar: `feature_list.json` quedó en 36 KB y `PROGRESS.md` en 12 KB. El arranque pasó de ~150k a ~12k tokens, y deja de crecer.

Regla aproximada: 1 token ≈ 4 caracteres de texto en inglés o JSON (algo menos en español o código). 100 KB ≈ 25–30k tokens.

## 2. Principios

1. **El contexto de arranque debe ser O(1), no O(historia).** Lo que se lee siempre tiene que tener un tamaño acotado: resumen del estado y la feature en curso. El historial se guarda, pero no se lee por defecto.
2. **Archivar no es borrar.** La evidencia es valiosa para auditoría y validación independiente. Se mueve tal cual a `docs/archive/` y se busca cuando hace falta; nunca se lee entera.
3. **Un script resume y el agente profundiza.** Un hook de solo lectura (`harness-context`) calcula lo que el agente necesita para decidir. Luego el agente lee solo la entrada de la feature elegida (buscando por `id`) y su spec.
4. **Leer de forma dirigida.** En `AGENTS.md`, las lecturas obligatorias deben ser pocas y estables (glosario, brief). El resto va en "léelo solo si toca X".
5. **La regla de recorte vive en el proceso.** Si "mantén `PROGRESS.md` corto" no aparece en el cierre de sesión, el archivo vuelve a crecer.

## 3. Patrones concretos

### `feature_list.json`: entradas cerradas mínimas

- Features abiertas (`not_started`, `in_progress`, `passing`): registro completo.
- Features `accepted`: solo `id`, `area`, `title`, `status` y `depends_on`, lo justo para resolver dependencias. El registro completo (comportamiento, verificación, evidencias, notas) se mueve **en el mismo cambio que la acepta** a `docs/archive/feature-evidence-accepted.json`.

### `PROGRESS.md`: ventana deslizante

- Primero lo más reciente, con un máximo de N sesiones (en Chartiko, 10).
- Al añadir una sesión que supera el límite, la más antigua pasa al principio de `docs/archive/progress-log.md`.
- Una línea al principio indica dónde está el archivo histórico.

### Texto para `AGENTS.md`

```markdown
3. Pick the work from the hook output: the user's requested task, otherwise the first
   ready unfinished feature. Then read only what that work needs: its entry in
   `feature_list.json` (search by `id`), its spec in `docs/specs/`, and the `PROGRESS.md`
   sections that mention it. Do not read `feature_list.json`, `PROGRESS.md` or
   `docs/archive/` in full; search them.
```

Y en el cierre de sesión:

```markdown
1. Update `PROGRESS.md`, adding the new session at the top; if it now has more than 10
   sessions, move the oldest to `docs/archive/progress-log.md`.
2. Update `feature_list.json`; if a feature became `accepted`, archive its full record.
```

### Salida de la verificación

`init` se ejecuta varias veces por feature y toda su salida entra en el contexto. Conviene que los tests impriman un resumen (`pytest -q`, reporter compacto en Vitest, `artisan test` sin modo verbose) y que solo se vea el detalle de lo que falla.

### Documentos grandes de referencia

Los documentos de cientos de KB (en Chartiko, `ARCHITECTURE.md` de 108 KB y `CONSTRAINTS.md` de 80 KB) no deben estar en "Read First". Lo mejor es que tengan un índice con secciones bien tituladas, para que el agente busque la sección que necesita en lugar de leer el archivo entero.

## 4. Hábitos de sesión (sin tocar el repositorio)

- **Una feature por conversación y `/clear` al terminar.** Si la conversación sigue, el contexto de la feature anterior se arrastra y se paga en cada turno.
- **Pedir la tarea concreta.** "Implementa `stock-comparison` según `docs/specs/stock-comparison.md`" evita que el agente explore el backlog para decidir qué hacer.
- **Delegar las búsquedas amplias en subagentes de exploración.** Solo vuelve la conclusión, no los volcados de archivos.
- **`/compact` en sesiones largas**, antes de que el contexto se llene de salidas de tests ya irrelevantes.
- **No pedir "relee todo" por precaución.** Si algo cambió, basta con nombrar el archivo y la sección.

## 5. Checklist para un proyecto nuevo

- [ ] `AGENTS.md` lista pocas lecturas obligatorias y todas de tamaño estable.
- [ ] Hay un script de contexto de solo lectura que resume el estado en menos de 2 KB.
- [ ] El arranque dice explícitamente que no se lean enteros los archivos que crecen.
- [ ] `feature_list.json` archiva el registro completo al aceptar una feature.
- [ ] `PROGRESS.md` tiene un máximo de sesiones y un archivo histórico en `docs/archive/`.
- [ ] El cierre de sesión incluye el recorte de esos dos archivos.
- [ ] La verificación imprime resúmenes, no el detalle de cada test.
- [ ] Los documentos de referencia grandes están en "léelo solo si…", con índice.
- [ ] Revisar de vez en cuando el tamaño de los archivos del harness (`wc -c` o `Get-Item | Select Length`). Si alguno pasa de ~50 KB, toca archivar.
