# Chartiko

Screener técnico de acciones del S&P 500 con gráficos interactivos, señales, patrones chartistas y alertas.

**Producción:** [www.chartiko.com](https://www.chartiko.com)

Chartiko descarga cada día las cotizaciones de cierre, calcula indicadores técnicos y detecta señales y patrones. Con eso genera una lista ordenada de **Candidates** que se puede filtrar y explorar en un gráfico interactivo. La información es solo orientativa y educativa: **no es asesoramiento de inversión**.

> Este proyecto se ha desarrollado con agentes de IA siguiendo un método de harness, features y SDD. El proceso está documentado en [docs/desarrollo-con-ia.md](docs/desarrollo-con-ia.md).

## Funcionalidades

| Para quién | Qué ofrece |
|---|---|
| Cualquier visitante | Screener con filtros combinables (cruces de medias, alineación de tendencia, RSI, MACD, RVOL/volumen, ruptura de pivote, patrones chartistas), Candidates ordenados y paginados, gráfico interactivo por instrumento y manual «Cómo funciona». En español e inglés. |
| Usuario registrado | Watchlist, Screeners guardados, alertas sobre Screeners, señales y patrones con notificaciones en la app, pronóstico experimental Jev a 1 y 5 sesiones, y ficha de empresa generada con IA. |
| Admin | Panel de ingesta: lanzar ejecuciones, consultar su log y reintentar fallos. Usuarios, sesiones y actividad de acceso. Backtesting del pronóstico Jev. |

Las **señales** son deterministas: cruces golden y death, alineación de medias, RVOL, RSI, MACD y ruptura de pivote. Los **patrones chartistas** son doble techo, doble suelo, taza con asa y bandera alcista. Los datos son de cierre diario: no hay datos intradía.

## Arquitectura

```
Navegador ──HTTPS──► Nginx ─┬─ /            → SPA React (estática)
                            └─ /api, /sanctum → PHP-FPM → Laravel ──► PostgreSQL (vía PgBouncer)
                                                  │  ▲
                                   HTTP local     ▼  │  cola (database) + scheduler (cron)
                                               Engine Python (FastAPI, 127.0.0.1:8090)
                                               descarga EOD, indicadores, señales, patrones
```

- **Laravel** es el único que accede a la base de datos. Se encarga de la API, la autenticación (cookies de sesión de Sanctum con CSRF), los roles, la cola, el scheduler y las integraciones de IA.
- **Engine Python**: hace los cálculos puros. Recibe barras y devuelve resultados por HTTP, sin conexión a la base de datos.
- **SPA**: React 19, TypeScript, Vite y Tailwind 4, en `frontend/`.
- **Pipeline diario**: `ingestion:pipeline` se ejecuta tras el cierre del mercado de EE. UU. Descarga las barras, calcula indicadores, detecta señales y patrones, refresca el Screener y evalúa las alertas.

El detalle está en [ARCHITECTURE.md](ARCHITECTURE.md) y en la infografía [docs/arquitectura-infografia.svg](docs/arquitectura-infografia.svg).

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.4, Laravel 13, Sanctum |
| Engine | Python 3.10+, FastAPI, httpx |
| Frontend | React 19, TypeScript, Vite, Tailwind 4, Vitest, oxlint |
| Datos | SQLite en local y en tests; PostgreSQL con PgBouncer en producción |
| Infraestructura | VPS OVH (Ubuntu), Nginx, systemd, Certbot, GitHub Actions |
| IA en el producto | TypeSafe AI (Jev) para el pronóstico; API compatible con OpenAI (DevExpert Inference) para la ficha de empresa |

## Puesta en marcha local (Windows)

El entorno de desarrollo es Windows con [Laragon](https://laragon.org) y PowerShell 5.1.

**Requisitos:**
- PHP 8.4 en el PATH
- Composer 2
- Node 22
- Python 3.10 o superior

```powershell
# 1. Dependencias y configuración
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed                      # universo S&P 500

# 2. Verificación completa: tests de Laravel, lint, tests y build del SPA, tests del engine.
#    Instala lo que falte (vendor, node_modules, venv del engine).
.\init.ps1
```

Para levantar la aplicación, abre cada proceso en su propia terminal:

```powershell
php artisan serve --port=8000                  # API Laravel
php artisan queue:work                         # ejecuciones de ingesta lanzadas desde Admin
engine\.venv\Scripts\python.exe -m app         # engine en http://127.0.0.1:8090
npm --prefix frontend run dev                  # SPA en http://localhost:5173 (hace proxy de /api y /sanctum)
```

Para cargar datos y dar acceso de Admin:

```powershell
php artisan ingestion:pipeline --force         # descarga, indicadores, señales, patrones y Screener
php artisan app:make-admin tu@email.com        # convierte en Admin una cuenta ya registrada
```

Las funciones de IA vienen **desactivadas** por defecto. Se activan con `JEV_*` y `AI_*` en `.env`, según se explica en [docs/forecast-jev-operations.md](docs/forecast-jev-operations.md) y [docs/ai-company-card-operations.md](docs/ai-company-card-operations.md).

## Tests

| Comando | Qué ejecuta |
|---|---|
| `.\init.ps1` | Verificación completa; es la referencia antes de cualquier cambio |
| `php artisan test` | Tests de Laravel (SQLite en memoria) |
| `npm --prefix frontend run test` | Tests de Vitest del SPA |
| `npm --prefix frontend run lint` | TypeScript y oxlint |
| `cd engine; .venv\Scripts\python.exe -m pytest -q` | Tests del engine |

El CI ejecuta los tests de Laravel contra **SQLite y PostgreSQL**, y además los tests del engine y el build del SPA.

## Despliegue

Cada push a `main` pasa por el workflow [`.github/workflows/ci-deploy.yml`](.github/workflows/ci-deploy.yml):

1. Ejecuta los tests.
2. Sube la release al VPS por SSH, con verificación estricta de la clave del host.
3. Instala las claves de los proveedores de IA desde los secrets de GitHub.
4. Aplica las migraciones y cachea la configuración.
5. Cambia de forma atómica a la nueva release.
6. Hace un health check y, si falla, vuelve a la release anterior.

El procedimiento completo, los permisos, PostgreSQL y PgBouncer, los backups y el rollback están en [deploy/README.md](deploy/README.md). Los directorios y servicios del VPS conservan el nombre interno `alphapulse`.

## Estructura del repositorio

```
app/, routes/, config/, database/, lang/   Laravel (API, comandos artisan, modelos, migraciones, i18n)
engine/                                    Engine Python (FastAPI) y sus tests
frontend/                                  SPA React
deploy/                                    Scripts de servidor, Nginx, systemd, PgBouncer, backups
docs/                                      Brief, modelo de dominio, specs, seguridad, legal, operación
docs/specs/                                Una spec por feature (SDD)
alphapulse/                                Prototipo visual de referencia (no es base del producto)
.opencode/skills/, .agents/skills/         Skills del proyecto para los agentes de IA
AGENTS.md, CLAUDE.md                       Instrucciones para los agentes
feature_list.json, PROGRESS.md, init.ps1   Harness: backlog, diario verificado, verificación
```

## Documentación

| Documento | Contenido |
|---|---|
| [CONTEXT.md](CONTEXT.md) | Lenguaje del dominio (Universe, Daily Bar, Signal, Candidate…) |
| [docs/build-brief.md](docs/build-brief.md) | Problema, usuarios, objetivos y alcance del MVP |
| [ARCHITECTURE.md](ARCHITECTURE.md) · [CONSTRAINTS.md](CONSTRAINTS.md) · [DESIGN.md](DESIGN.md) | Arquitectura, reglas obligatorias y sistema visual |
| [feature_list.json](feature_list.json) · [PROGRESS.md](PROGRESS.md) | Estado de las features y diario de desarrollo |
| [docs/llamadas-api-jev-ia.md](docs/llamadas-api-jev-ia.md) | Cómo se llama a Jev y a la API de IA |
| [docs/security/](docs/security/) | Auditorías de seguridad del despliegue |
| [docs/desarrollo-con-ia.md](docs/desarrollo-con-ia.md) | Cómo se ha construido el proyecto con IA |

## Desarrollo con agentes de IA

El repositorio está preparado para que cualquier agente de código (Claude Code, Codex, OpenCode) retome el trabajo sin depender del historial del chat. El ciclo es:

1. Al empezar, el agente lee `AGENTS.md` y ejecuta `.\scripts\harness-context.ps1` para ver el contexto.
2. Elige la primera feature lista en `feature_list.json`.
3. Cada feature sigue el flujo **spec → implementación → validación independiente** con las skills `feature-spec`, `feature-implementer` y `feature-validator`, orquestadas por `feature-flow`.
4. Una feature solo pasa a `accepted` cuando la valida otro agente.
5. Toda la evidencia queda en `PROGRESS.md`.
