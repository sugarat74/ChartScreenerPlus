# Ficha de empresa IA: operación

La ficha de empresa IA (`ai-company-card`, especificación `docs/specs/ai-company-card.md`) es una hoja estructurada por Instrument que redacta un proveedor externo compatible con la API Chat Completions de OpenAI: sector e industria, descripción del negocio y una previsión cualitativa a tres meses. Desde `company-fundamentals-sec`, las métricas clave no las escribe el modelo: las calcula Chartiko con los informes 10-K/10-Q de la empresa en SEC EDGAR (sección «Fundamentales SEC» más abajo) y se envían al modelo como contexto verificado. Solo la ven y la generan usuarios registrados (`GET`/`POST /api/instruments/{ticker}/company-card`, con sesión Sanctum). Abrir el panel solo lee la base de datos; el proveedor se llama únicamente cuando el usuario pulsa «Generar previsión» y no existe una ficha fresca. El texto lo escribe el modelo y no está verificado; la interfaz lo indica siempre y separa las cifras SEC («Fuente: SEC (10-K/10-Q)») de la previsión IA.

## Estado por defecto

La función se entrega **desactivada** (`AI_CARD_ENABLED=false`) y sin proveedor elegido. Mientras no esté activada y configurada, los usuarios registrados ven «La previsión con IA aún no está disponible» (las métricas SEC se siguen viendo), `POST` responde `503 provider_not_configured` y no sale ninguna petición del servidor. Las pruebas automáticas usan siempre `Http::fake`; ningún test ni CI llama a una API real.

## Activación en producción

Antes de activar:

1. Elegir proveedor y modelo (y, con ello, el presupuesto mensual). El modelo debe admitir `response_format` `json_schema` estricto o, al menos, `json_object`.
2. Revisar las condiciones de tratamiento de datos del proveedor (encargado del tratamiento, ubicación, conservación y uso para entrenamiento) y **nombrarlo en la política de privacidad** (sección «Funciones de IA» de `frontend/src/i18n/legal/{es,en}.ts`, que hoy dice que se indicará al activarlo), subiendo `config/legal.php` `updated_at`. Decidir también si esa sección debe cubrir Jev/TypeSafe, que hoy no figura.
3. Decidir si se pueden enviar datos técnicos de mercado de Chartiko (cierre, variación, indicadores, Signals y patrones) al proveedor. Si no está confirmado, dejar `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=false`: solo se envía la identidad del instrumento (ticker, empresa, sector y bolsa), que es pública.

Después, en `/var/www/alphapulse/shared/.env` del VPS (nunca en Git ni en el navegador):

```
AI_CARD_ENABLED=true
AI_BASE_URL=https://api.proveedor.example/v1   # https obligatorio
AI_API_KEY=<clave del proveedor>
AI_MODEL=<id exacto del modelo>
# Opcionales (valores por defecto entre paréntesis)
AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=false       # (false)
AI_TIMEOUT_SECONDS=40                           # (40) por debajo de los 60 s de nginx
AI_MAX_TOKENS=8000                              # (8000) los modelos que razonan gastan tokens antes del JSON
AI_MAX_TOKENS_PARAM=max_tokens                  # o max_completion_tokens en modelos OpenAI recientes
AI_TEMPERATURE=0.2                              # null para no enviarla (modelos que no la aceptan)
AI_RESPONSE_FORMAT=json_schema                  # o json_object si el proveedor no admite json_schema
AI_RESPONSE_FORMAT_FALLBACK=true                # reintenta una vez con json_object ante un 400
AI_CARD_FRESHNESS_DAYS=7
AI_CARD_REQUESTS_PER_MINUTE=5
AI_CARD_USER_DAILY_LIMIT=10
AI_CARD_DAILY_LIMIT=200
AI_CARD_LOG_RETENTION_DAYS=90
```

`deploy.sh` ejecuta `config:cache` y `route:cache`: tras editar `.env` hay que volver a cachear (`php artisan config:cache` y `php artisan route:cache` en el release activo, como `deploy`) o redesplegar. `AI_CARD_REQUESTS_PER_MINUTE` forma parte de la ruta, así que solo cambia al reconstruir la caché de rutas. Si `AI_BASE_URL` no es `https://` (salvo `http://localhost` o `http://127.0.0.1` para servidores locales), la función se considera no configurada.

Comprobación tras activar: con una cuenta registrada, abrir `/instruments/NVDA#ai-company-card`, pulsar «Generar previsión» y verificar que aparece la ficha en menos del tiempo límite; recargar y comprobar que se reutiliza (no aparece una fila nueva en `ai_company_card_generations`). Repetir en inglés: cada idioma genera y guarda su propia ficha.

## Configuración de producción (2026-10-16)

Decisiones del propietario: proveedor **DevExpert Inference** (`https://inference.devexpert.io/v1`, modelo `chat`, el mismo de la prueba real del 15 de octubre), envío de datos técnicos permitido (`AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true`, son datos públicos) y límites por defecto. La política de privacidad nombra al proveedor (y también a TypeSafe AI para Jev) desde `updated_at` 2026-10-16.

Igual que la clave de Jev, la clave no se escribe a mano en el VPS. Vive en el secreto `AI_API_KEY` del entorno GitHub `production`. En cada despliegue, `ci-deploy.yml` la pasa por SSH (stdin) a `deploy/configure-ai-env.py`, que escribe en `shared/.env`, sin imprimir nada:

- `AI_API_KEY`,
- `AI_CARD_ENABLED=true`,
- `AI_BASE_URL`,
- `AI_MODEL`,
- `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true`.

Después `deploy.sh` vuelve a cachear la configuración. Si falta el secreto, el despliegue falla antes de activar la release.

- Alta o rotación de la clave: `gh secret set AI_API_KEY --env production` (la pide por teclado; no la pongas en la línea de comandos ni en el chat) y redesplegar.
- Cambiar proveedor o modelo: editar `SETTINGS` en `deploy/configure-ai-env.py`, actualizar la política de privacidad si cambia el proveedor y desplegar.
- Desactivar de urgencia: poner `AI_CARD_ENABLED=false` en `shared/.env` y `config:cache`. El siguiente despliegue lo volvería a activar, así que cambia también el script.

## Coste y límites

- Una ficha por Instrument, idioma y versión de esquema; se reutiliza para todos los usuarios mientras sea fresca (7 días y mismo modelo y versión de prompt). Reutilizar no cuenta para ningún límite.
- 5 peticiones `POST` por minuto y usuario; 10 intentos de generación por usuario y día; 200 intentos en total por día (días UTC). Cada intento con llamada al proveedor cuenta, haya salido bien o mal.
- Coste máximo diario aproximado = `AI_CARD_DAILY_LIMIT × (tokens de entrada + salida por ficha) × precio del modelo`. Los tokens reales por intento quedan en `ai_company_card_generations` (`prompt_tokens`, `completion_tokens`, `total_tokens`).
- La llamada es síncrona: un worker PHP queda ocupado hasta `AI_TIMEOUT_SECONDS`. Si el modelo elegido es más lento, el siguiente paso es una cola con sondeo, no subir el límite por encima de los 60 s de nginx.

## Registro de generaciones

`ai_company_card_generations` guarda un registro por intento: Instrument, cuenta solicitante (`user_id`, que pasa a `null` si se borra la cuenta), idioma, modelo, versiones, formato usado, estado (`succeeded`, `invalid_response`, `provider_error`), código de error, tokens y latencia. **No guarda el prompt ni la respuesta.** Se borra automáticamente a los `AI_CARD_LOG_RETENTION_DAYS` días mediante `model:prune` (tarea diaria `prune-login-events` de `routes/console.php`). No hay pantalla Admin todavía (seguimiento propuesto: `ai-company-card-admin-log`); se puede consultar por SQL.

## Validación y errores

La respuesta del modelo se valida en el servidor contra `company-card-v2` (claves exactas `company`, `outlook`, `limitations`, `sources`; sin métricas; tipos, enumerados, horizonte 3, longitudes y ticker). Las fichas `company-card-v1` (con cifras del modelo) siguen en la tabla pero ya no se muestran. Una respuesta truncada, rechazada, con claves de más o de menos, o de otro ticker devuelve `503 invalid_provider_response`, no se guarda y no sustituye a la ficha anterior. Errores de red, tiempo agotado o respuestas 4xx/5xx devuelven `503 provider_unavailable`. Dos peticiones simultáneas para la misma ficha comparten un bloqueo: la segunda espera y reutiliza la primera; si espera más de `AI_TIMEOUT_SECONDS + 10` s, `503 busy`. Los logs de aplicación solo registran la clase de excepción o el estado HTTP, nunca la clave ni el contenido.

## Cambiar el esquema o el prompt

Cualquier cambio en `CompanyCardSchema` o `CompanyCardPrompt` exige subir `schema_version` o `prompt_version` en `config/ai_card.php`. Un cambio de `prompt_version` o de `AI_MODEL` marca las fichas existentes como desactualizadas (se siguen mostrando hasta regenerarlas); un cambio de `schema_version` las deja fuera de lectura, porque el contenido antiguo no cumple el esquema nuevo.

## Fundamentales SEC

Las métricas (PER, BPA, capitalización, ingresos y su crecimiento, EBITDA y margen, deuda neta/EBITDA, ROIC aproximado y rentabilidad por dividendo) se calculan con los datos XBRL públicos de SEC EDGAR (`companyfacts` y `submissions`, dominio público, sin clave) y nuestro último cierre. Especificación: `docs/specs/company-fundamentals-sec.md`. Se ven aunque el proveedor de IA esté desactivado.

### Activación

**Producción (2026-10-17):** no se edita a mano. En cada despliegue, `deploy/configure-fundamentals-env.py`, invocado desde `ci-deploy.yml`, escribe en `shared/.env` estas dos variables:
- `SEC_FUNDAMENTALS_ENABLED=true`
- `SEC_USER_AGENT="Chartiko Roberto Rodriguez contacto@chartiko.com"`

El User-Agent va solo en ASCII, sin tildes, porque se envía como cabecera HTTP. Para cambiar el contacto, edita `SETTINGS` en ese script y despliega.

Si no hay carga inicial manual, la primera ejecución programada (de lunes a viernes a las 07:00, hora de Nueva York) descarga los datos de todas las empresas sin fila guardada.

Variables, para otros entornos (`.env`, y después volver a cachear la configuración):

```
SEC_FUNDAMENTALS_ENABLED=true
SEC_USER_AGENT="Chartiko <buzón de contacto>@chartiko.com"   # nombre + email reales (política de acceso de la SEC)
SEC_REQUESTS_PER_SECOND=5                                      # (5) máximo 9; la SEC permite 10/s
SEC_TIMEOUT_SECONDS=30                                         # (30)
```

Sin `SEC_USER_AGENT` válido (nombre y email) el comando termina con código 1 sin hacer ninguna petición, la tarea programada no se ejecuta y la API devuelve `fundamentals: null`. `SEC_DATA_BASE_URL` y `SEC_TICKERS_URL` solo se cambian para pruebas locales con servidores falsos; en producción se dejan sin definir.

Carga inicial tras activar (una vez, unas 1.000 peticiones, unos 4 minutos a 5 peticiones/s):

```
php artisan fundamentals:refresh --force
```

Después, la tarea `fundamentals-refresh` corre de lunes a viernes a las 07:00 (hora de Nueva York), separada del pipeline diario: una petición pequeña de `submissions` por empresa y `companyfacts` solo si hay un 10-K/10-Q nuevo, un informe pendiente o `--force`. Para un subconjunto: `php artisan fundamentals:refresh --ticker=AAPL --ticker=BRK.B`.

### Seguimiento

- `company_fundamentals.status`: `ok`, `partial` (faltan conceptos; ver `inputs`), `no_cik` (el ticker no está en el mapa de la SEC), `no_data` (la SEC no tiene datos XBRL) o `error` (primer intento fallido). `last_error` guarda el último fallo (p. ej. `sec_unavailable (503)`) sin borrar las últimas cifras buenas.
- Un `403` de la SEC detiene toda la ejecución: revisar `SEC_USER_AGENT`.
- `pending_accession`/`pending_form`/`pending_filed_at`: hay un informe listado en `submissions` que aún no está en `companyfacts`; se vuelve a leer en cada ejecución.
- Un informe nuevo cambia `latest_accession` y deja desactualizadas (no borra) las previsiones IA hechas con el anterior.
- Aproximaciones documentadas: BPA de 12 meses por aritmética de acumulados, ROIC con el capital al cierre del periodo, D&A como `Depreciation` + amortización de intangibles cuando no hay concepto conjunto, capitalización multi-clase con el cierre de una sola clase. Bancos y aseguradoras (SIC 6000-6411) muestran EBITDA, margen, deuda neta/EBITDA y ROIC como «no aplica».
- Comprobación manual opcional (nunca en CI), con el `SEC_USER_AGENT` real: `php artisan fundamentals:refresh --ticker=AAPL --ticker=JPM --ticker=XOM` y comparar con la tabla de la especificación.

## Screener en lenguaje natural (mismo proveedor)

El Screener en lenguaje natural (`ai-screener-natural-language`) comparte `AI_BASE_URL`, `AI_API_KEY` y `AI_MODEL` con la ficha, pero tiene su propio interruptor `AI_SCREENER_ENABLED` (por defecto `false`) y sus límites en `config/ai_screener.php` (6/min por usuario, 30 por usuario y 600 en total cada 24 h, registro de 90 días sin el texto). En producción, `deploy/configure-ai-env.py` escribe `AI_SCREENER_ENABLED=true` en cada despliegue; para apagarlo, quitarlo de `SETTINGS` (o poner `false`) y desplegar. Si la ficha se apaga y el Screener sigue encendido, el proveedor recibe solo peticiones del Screener. Detalle de la llamada: [llamadas-api-jev-ia.md](llamadas-api-jev-ia.md), sección 3.

## Desactivar

Poner `AI_CARD_ENABLED=false` y volver a cachear la configuración. Las fichas guardadas se siguen mostrando (marcadas como desactualizadas cuando corresponda) y no se hace ninguna llamada.
