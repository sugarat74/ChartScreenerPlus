# Chartiko: cómo se ha desarrollado con IA

Este documento resume cómo se construyó Chartiko (https://www.chartiko.com) con agentes de IA. Cubre el método de trabajo (harness, features, SDD), las skills que se crearon, el reparto de roles entre agentes y lo aprendido. Las cifras salen del repositorio a 2026-10-17: historial de Git, `feature_list.json`, `PROGRESS.md` y las carpetas `docs/` y `.opencode/`.

## 1. El proyecto en una página

**Qué es.** Chartiko es un screener técnico de acciones del S&P 500. Cada día descarga las cotizaciones de cierre, calcula indicadores (medias, RSI, MACD, RVOL…) y detecta señales y patrones chartistas. Muestra una lista ordenada de candidatos con su gráfico interactivo. Los usuarios registrados tienen watchlist, screeners guardados y alertas, y un panel Admin controla la ingesta.

**Stack**

| Pieza | Tecnología |
|---|---|
| API, autenticación y Admin | Laravel 13 (PHP 8.4) |
| Motor de cálculo | Python con FastAPI |
| Interfaz | SPA en React 19 + Vite + Tailwind |
| Base de datos | PostgreSQL con PgBouncer en producción, SQLite en local y en los tests |
| Producción | VPS en OVH con Nginx y systemd, desplegado por GitHub Actions |

**El desarrollo en números**

| Métrica | Valor |
|---|---|
| Duración | Del 15 de septiembre al 16 de octubre de 2026, con 16 días con commits |
| Commits | 105, de los que 36 son PR mergeados |
| Features | 54 en `feature_list.json`: 50 `accepted` y 4 pendientes |
| Specs | 41 en `docs/specs/`, más 1 en formato SDD de 3 ficheros (`specs/pronostico-jev/`) |
| Skills propias del proyecto | 15 en `.opencode/skills/`, replicadas en `.agents/skills/` |
| Tests en la última verificación | Unos 380 en Laravel, 154 en Vitest y 67–77 en el engine |
| Commits con co-autoría de Claude | 57, desde el 3 de octubre |

Cada commit con co-autoría lleva la línea `Co-Authored-By: Claude …`. Los commits anteriores al 3 de octubre no registran qué agente los escribió.

## 2. La idea central: el humano dirige y los agentes ejecutan

El proyecto no se construyó pidiendo "hazme una app" en un chat. Se montó un **sistema de trabajo dentro del propio repositorio** para que cualquier agente pudiera retomarlo en una sesión nueva sin depender de la memoria de la conversación.

- **El humano** decide el producto: el alcance, las prioridades, los datos legales y de negocio y qué se publica. También aprueba las acciones con efectos externos, como un merge que despliega, los secretos o el VPS.
- **Los agentes** escriben las specs, implementan, prueban y validan, y dejan constancia por escrito de lo que han hecho.
- **El repositorio** es la memoria compartida. Los artefactos de la sección 3 son la fuente de verdad, por encima del chat.

Se usaron varios agentes de código sobre las mismas instrucciones. El repo trae configuración para OpenCode (`.opencode/`), Codex (`.agents/`, `agents/openai.yaml`) y Claude Code (`CLAUDE.md`, que solo apunta a `AGENTS.md`). Así las reglas son las mismas sea cual sea el agente.

## 3. El harness: el andamiaje que mantiene a los agentes en el carril

El harness es el conjunto mínimo de ficheros que todo agente lee al empezar y actualiza al terminar. Se creó con la skill `harness-starter` justo después del brief.

| Fichero | Para qué sirve |
|---|---|
| `AGENTS.md` | Instrucciones de trabajo: entorno (Windows, PowerShell 5.1), alcance del MVP, flujo de arranque, reglas y definición de "hecho". |
| `CLAUDE.md` | Una línea que remite a `AGENTS.md`, para no duplicar instrucciones por herramienta. |
| `feature_list.json` | Backlog y fuente de verdad del estado de cada feature: dependencias, criterios de verificación y evidencias. |
| `PROGRESS.md` | Diario de sesiones: qué se hizo, qué se verificó con qué resultado y qué queda pendiente. |
| `init.ps1` | Comando único de arranque y verificación: tests de Laravel, lint, tests y build del SPA, y tests del engine. Si no pasa, no se empieza nada nuevo. |
| `scripts/harness-context.ps1` | Hook de solo lectura al inicio de sesión: rama, cambios, features listas, bloqueadas y riesgos abiertos. |
| `CONTEXT.md` | Lenguaje del dominio (Universe, Daily Bar, Signal, Candidate…). Los agentes deben usar estos términos exactos. |
| `ARCHITECTURE.md`, `CONSTRAINTS.md`, `DESIGN.md` | Arquitectura vigente, reglas con MUST/MUST NOT y sistema visual. Las actualiza cada feature que los afecta. |
| `docs/risks-and-open-questions.md` | Riesgos y decisiones abiertas, para que no se pierdan entre sesiones. |

**Ciclo de vida de una feature**

`not_started` → `in_progress` → `passing` → `accepted`

- `passing`: el implementador la ha terminado y verificado por su cuenta.
- `accepted`: un validador independiente la ha aceptado.

Una feature solo está lista para empezar cuando **todas sus dependencias están `accepted`**. Así los agentes no construyen sobre algo sin validar.

**Definición de hecho** (`AGENTS.md`):
- comportamiento implementado;
- verificación ejecutada de verdad;
- evidencia registrada;
- repositorio arrancable con `init.ps1`;
- documentación actualizada.

## 4. Del problema al backlog: el brief

La primera sesión (16 de septiembre) usó la skill `build-brief`. Es una entrevista guiada que produce `docs/build-brief.md`:
- problema y usuarios;
- objetivos y no-objetivos;
- slice del MVP;
- forma de validarlo.

De ahí salieron `docs/domain-model.md`, `docs/user-and-access-model.md`, `docs/technical-discovery.md` y la lista inicial de features con sus dependencias.

Hubo además un **prototipo visual** hecho con Google AI Studio (`alphapulse/`, React con datos falsos). `AGENTS.md` lo declara **solo como referencia de diseño e intención**: se puede mirar, pero nunca usar como base del producto. Separar prototipo y producto evitó que los agentes reutilizaran código de juguete.

## 5. SDD: desarrollo guiado por especificaciones

Cada feature pasa por una **spec** antes de tocar código. La spec es un contrato entre tres roles: quien planifica, quien implementa y quien valida.

**Formato principal** (`feature-spec`, en `docs/specs/<feature>.md`, unas 100–250 líneas). Contiene:
- objetivo y **no-objetivos** (lo que no se debe construir);
- historia de usuario y escenarios de aceptación Given/When/Then;
- investigación del repo: ficheros inspeccionados, patrones que seguir y huecos;
- enfoque técnico, ficheros esperados y tareas ordenadas;
- impacto en la documentación;
- plan de verificación, evidencias que capturar y **checklist para el validador**.

**Formato alternativo** (`sdd-propose` → `sdd-apply` → `sdd-archive`). Usa `specs/<feature>/{spec,design,tasks}.md`, al estilo OpenSpec. Se probó con el pronóstico Jev.

**Por qué funciona con IA:**
- Los no-objetivos y la checklist del validador frenan el *scope creep*, que es el fallo típico de un agente.
- La sección "Implementation Findings" que añade el implementador deja escrito por qué la realidad se desvió de la spec.
- Si una spec queda demasiado grande, la skill obliga a proponer partirla en vez de escribir una spec vaga.

## 6. Tres roles y un orquestador

El trabajo de cada feature se reparte entre agentes con roles separados:

```
            ┌──────────────┐
  usuario → │ feature-flow │  (orquestador: elige feature y rol, revisa, hace commit)
            └──────┬───────┘
     ┌─────────────┼──────────────┐
     ▼             ▼              ▼
  planner     implementer      validator
 (feature-    (feature-        (feature-
  spec)        implementer)     validator)
  escribe      implementa,      verifica de forma
  la spec      prueba, deja     independiente:
               `passing`        accept / revise / block
```

Las reglas importantes de `feature-flow`:
- Una feature cada vez, y sin ejecutar los roles en paralelo.
- `passing` no significa aceptada. Solo el validador independiente puede pasar una feature a `accepted`.
- Si el validador devuelve `revise`, la reparación vuelve al implementador y después otra vez al validador, en bucle hasta `accept` o `block`.
- Los subagentes no hacen commits. El orquestador hace un único commit convencional (`feat: complete <feature>`) después de la aceptación.
- Al final, el orquestador entrega al humano un resumen: qué se hizo, cómo probarlo, la validación, el commit y lo siguiente.

**La validación independiente encontró problemas reales.** `PROGRESS.md` registra varias vueltas `revise`. Algunos ejemplos:

| Feature | Qué detectó el validador |
|---|---|
| Backtesting de Jev | Mezcla de versiones de modelo y ejecuciones atascadas si fallaba la cola. |
| Backtesting a 100 tickers | Miles de jobs podían bloquear la ingesta diaria, y el lock de arranque caducaba. |
| Hook de contexto de sesión | Las features sin dependencias aparecían como bloqueadas, y el hook reescribía `.git/index`. |
| Diagramas de señales | En el dibujo de la ruptura de pivote, el penúltimo cierre ya estaba por encima del pivote. |

En todos los casos se reparó y se volvió a validar.

**Trabajo en paralelo con worktrees.** Las features largas se implementaron en git worktrees aislados (`.claude/worktrees/…`). Varios agentes podían trabajar sin pisarse y sin tocar la base de datos local del usuario.

## 7. Skills propias: convertir lo aprendido en procedimientos reutilizables

Cuando una tarea se repetía o necesitaba criterio experto, se convirtió en una **skill**. Una skill es una carpeta con `SKILL.md` (cuándo usarla y el procedimiento) más referencias, plantillas y checklists.

| Skill | Tipo | Qué hace |
|---|---|---|
| `build-brief` | Descubrimiento | Entrevista guiada que produce el brief del proyecto o de una feature grande. |
| `harness-starter` | Harness | Crea o actualiza `AGENTS.md`, `init`, `PROGRESS.md` y `feature_list.json`. |
| `feature-spec` | SDD | Spec lista para implementar de una sola feature. |
| `feature-implementer` | SDD | Implementa exactamente una spec y la deja `passing` con evidencias. |
| `feature-validator` | SDD | Valida de forma independiente y devuelve accept, revise o block. |
| `feature-flow` | Orquestación | Encadena planner → implementer → validator hasta la aceptación. |
| `sdd-propose`, `sdd-apply`, `sdd-archive` | SDD alternativo | Spec en tres ficheros, aplicación de tareas y archivo. |
| `github-flow` | Git | Ramas cortas sobre un `main` siempre desplegable. |
| `web-deploy-security` | Dominio | Auditoría de seguridad del despliegue con informe y dictamen (APTO / NO APTO). |
| `web-legal-compliance-eu` | Dominio | Aviso legal (LSSI), privacidad (RGPD), cookies (AEPD) y transferencias. |
| `web-marketing-seo-geo` | Dominio | SEO, GEO (motores generativos), contenido de lanzamiento y accesibilidad para agentes. |
| `web-to-mobile` | Dominio | Evaluación y plan para llevar la web a Android e iOS. |

Algunas skills se registraron como features del backlog (por ejemplo `web-deploy-security-skill` y `web-legal-compliance-eu-skill`). Así también pasaron por validación.

**Ejemplo de skill de dominio aplicada a producción.** La skill `web-deploy-security` auditó el VPS (`docs/security/`):
- **Primer dictamen: NO APTO.** Los directorios del release eran escribibles por `www-data` y el CI confiaba en un `ssh-keyscan` sin verificar.
- Se corrigieron los dos problemas.
- Se revalidó y el dictamen quedó registrado.

## 8. MCP y hooks

### MCP (Model Context Protocol)

| MCP | Dónde | Para qué se usó |
|---|---|---|
| **Playwright MCP** (`@playwright/mcp`, con Edge) | Claude Code, a nivel de usuario. Instalado el 8 de octubre (`PROGRESS.md`). | QA en un navegador real: implementadores y validadores abrían la app, navegaban, cambiaban de idioma, probaban a 390 px y hacían capturas. Varias features registran "Edge QA N/N" como evidencia, por ejemplo 36/36 en alertas de patrones y 21/21 de lectura anónima en producción. |
| **node_repl** | Codex, a nivel de usuario | Entorno Node que el agente usa como herramienta auxiliar. |
| **MCP propio de Chartiko** (`admin-ops-mcp`) | Producto, planificado | Spec escrita (`docs/specs/admin-ops-mcp.md`) para un servidor MCP en Laravel (`laravel/mcp`). Con él, un agente operará el Admin con tokens con permisos, auditados y con caducidad, en lugar de entrar por SSH como root. Aquí el MCP pasa de ser herramienta de desarrollo a parte del producto. |

El repositorio no incluye ninguna configuración MCP (`.mcp.json`). Los MCP de desarrollo están a nivel de usuario, porque son herramientas del puesto de trabajo y no del proyecto.

### Hooks

**Hook del harness, dentro del repositorio.** `scripts/harness-context.ps1` es un hook de solo lectura que se ejecuta al empezar cada sesión, como paso 2 del flujo de arranque de `AGENTS.md`. Muestra en segundos:
- la rama y los cambios locales;
- la última verificación registrada;
- las features pendientes de validar;
- la primera feature lista para implementar;
- las features bloqueadas y los riesgos abiertos.

Se desarrolló como una feature más (`harness-session-context`) y pasó por validación independiente. El validador lo devolvió con `revise` por dos fallos:
- marcaba como bloqueadas las features sin dependencias;
- reescribía `.git/index`.

Se repararon los dos y quedó aceptado. Lo invoca la instrucción de `AGENTS.md`, sin depender de ningún agente concreto. Así lo pueden usar OpenCode, Codex y Claude Code por igual.

**Hooks del entorno, a nivel de usuario y fuera del repositorio.** Tanto Codex (`~/.codex/hooks.json`) como Claude Code (`~/.claude/settings.json`) tienen hooks sobre eventos del ciclo de vida del agente, por ejemplo `SessionStart`, `PreToolUse`, `PostToolUse`, `Stop` o `SubagentStart`/`SubagentStop`. Envían esos eventos a herramientas de seguimiento de sesiones de agentes: GitKraken CLI (`gk ai hook`), Orca y un script de estado propio. No cambian el código del proyecto, pero muestran otra forma de usar los hooks: observar y coordinar varios agentes que trabajan a la vez.

**Lección:** los hooks del repositorio sirven para dar **contexto y disciplina** a cualquier agente. Los hooks del puesto de trabajo sirven para **observar** los agentes. Ninguno sustituye a la verificación con `init.ps1`.

## 9. Recorrido por fases

| Fechas | Fase | Qué se hizo |
|---|---|---|
| 15–16 sep | Descubrimiento | Repositorio, brief, modelo de dominio y prototipo de referencia. |
| 29–30 sep | MVP | Las 23 features del MVP con specs y validación: scaffolding, esquema, autenticación y roles, ingesta EOD, indicadores, señales, scheduler, panel Admin, API y UI del screener, gráfico, watchlist, screeners guardados, portal y control de acceso. |
| 3–4 oct | Despliegue | Pipeline de CI/CD al VPS, marca Chartiko, SEO/GEO, auditoría de seguridad, remediación y publicación. |
| 6 oct | Infraestructura | Migración de SQLite a PostgreSQL con PgBouncer: copia transaccional, corte con rollback ensayado, backups con prueba de restauración y CI contra los dos motores. |
| 7–12 oct | Producto | Multi-idioma, Admin de usuarios y sesiones, cumplimiento legal UE, patrones chartistas, alertas, paginación y precálculo del screener, manual "Cómo funciona". |
| 13–15 oct | IA en el producto | Pronóstico experimental con Jev (TypeSafe AI), backtesting en Admin, ficha de empresa con IA y paneles solo para usuarios registrados. |
| 16 oct | Operación | Ficha IA activada con DevExpert Inference, fundamentales SEC (implementada, pendiente de cierre) y calibración de patrones (fase 1). |

## 10. La IA dentro del producto

Además de usarse para construir, la IA forma parte de Chartiko, con salvaguardas pensadas desde la spec. El detalle técnico está en `docs/llamadas-api-jev-ia.md`.

**Pronóstico Jev (TypeSafe AI).**
- **Qué recibe el modelo:** 252 barras, los indicadores y las señales del instrumento.
- **Qué devuelve:** la probabilidad de que el cierre suba, baje o se quede igual a 1 o 5 sesiones.
- **Salvaguardas:**
  - Se valida que las probabilidades sumen 1.
  - Hay caché por instrumento, horizonte y versión de la pregunta.
  - Se puede hacer backtesting desde Admin.
  - Siempre se presenta como experimental.

**Ficha de empresa con IA** (cualquier API compatible con OpenAI).
- **Formato de salida:** salida estructurada con JSON Schema estricto (`company_card_v1`).
- **Validación en el servidor:** se revisa la respuesta aunque el proveedor diga que respeta el esquema.
- **Lo que no puede contener:** el esquema no tiene campos de precio objetivo ni de recomendación.
- **Caché y límites:** cada ficha se reutiliza 7 días por instrumento e idioma, con límites diarios.
- **Datos de usuario:** el proveedor no recibe ninguno.

**Salvaguardas comunes:**
- las funciones vienen desactivadas por defecto;
- las claves solo existen como secretos de GitHub, que el CI escribe en el `.env` del servidor;
- en los logs no se guardan ni prompts ni respuestas;
- la política de privacidad nombra a los proveedores.

**Ejemplo de iteración con un modelo real.** Al probar la ficha contra el modelo `chat` aparecieron tres problemas:

| Problema | Ajuste |
|---|---|
| El modelo "razona" antes de responder y el JSON salía cortado. | Se subió el límite a 8000 tokens. |
| Se pasaba de longitud por pocas palabras y se rechazaba la ficha entera. | Los textos se recortan en vez de rechazarse. |
| Devolvía todas las métricas a `null`. | Se reescribió el prompt (v2) y se planificó una fuente verificada: los fundamentales de la SEC. |

## 11. Lecciones para el curso

1. **El repositorio es la memoria, no el chat.** `AGENTS.md` + `feature_list.json` + `PROGRESS.md` permiten que un agente nuevo retome el trabajo en minutos.
2. **Un único comando de verdad (`init.ps1`).** Si no pasa, no se avanza. Es lo que hace fiable la autoverificación del agente.
3. **Specs con no-objetivos y checklist de validación.** Son la mejor defensa contra el exceso de alcance y contra el "funciona en mi cabeza".
4. **Separar quien implementa de quien valida.** La validación independiente encontró fallos reales que el implementador había dado por buenos.
5. **Evidencias, no afirmaciones.** Cada estado lleva comandos ejecutados, números de tests, IDs de runs de CI y comprobaciones en producción.
6. **Las skills capturan conocimiento experto reutilizable** (seguridad, legal, SEO, móvil) y sirven para este proyecto y para los siguientes.
7. **El humano sigue decidiendo lo irreversible:** publicar, desplegar, manejar secretos, el contenido legal y el etiquetado que requiere juicio, como la calibración de patrones.
8. **Lo que costó:**
   - Las diferencias entre entornos: Windows y PowerShell 5.1 en local frente a Linux en el VPS, y SQLite frente a PostgreSQL.
   - Mantener la documentación al día en cada feature.
   - El tamaño de las specs: algunas features rozaron el límite y hubo que dividirlas o dejarlas en fases.

## 12. Dónde mirar en el repositorio

| Para ver… | Abre… |
|---|---|
| Las reglas que siguen los agentes | `AGENTS.md`, `CONSTRAINTS.md` |
| El backlog y su estado | `feature_list.json` |
| El diario de desarrollo | `PROGRESS.md` |
| Una spec completa de ejemplo | `docs/specs/ingestion-scheduler.md` |
| Las skills | `.opencode/skills/<nombre>/SKILL.md` |
| Los roles de los agentes | `.opencode/agents/{planner,implementer,validator}.md` |
| La arquitectura | `ARCHITECTURE.md`, `docs/arquitectura-infografia.svg` |
| El flujo de CI/CD | `.github/workflows/ci-deploy.yml`, `docs/ci-flujo-infografia.svg` |
| Las auditorías de seguridad | `docs/security/` |
| Las llamadas a IA | `docs/llamadas-api-jev-ia.md` |
