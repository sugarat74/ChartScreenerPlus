# Llamadas a proveedores externos: Jev y API de IA

Este documento describe las llamadas que Chartiko hace a proveedores externos: el pronóstico **Jev** (TypeSafe), la **ficha de empresa con IA** y el **Screener en lenguaje natural** (estas dos, a cualquier API compatible con OpenAI Chat Completions).

Refleja el código a 2026-10-19. Si hay diferencias, la referencia es el código: `JevClient`, `ForecastStateBuilder` y `config/forecast.php` para Jev; `OpenAiCompatibleClient`, `app/Services/CompanyCard/*` y `config/ai_card.php` para la ficha; `app/Services/Screener/Ai/*` y `config/ai_screener.php` para el Screener en lenguaje natural. Si cambias la pregunta, el esquema o el prompt, sube su versión (`question_version`, `schema_version`, `prompt_version`) y actualiza este documento.

---

## 1. Pronóstico Jev

La llamada se hace desde `JevClient::predict()` ([app/Services/Forecast/JevClient.php](../app/Services/Forecast/JevClient.php)) y la orquesta `ForecastService` ([app/Services/Forecast/ForecastService.php](../app/Services/Forecast/ForecastService.php)).

### 1.1 Petición HTTP

`POST https://api.typesafe.ai/v1/systemone`

**Cabeceras:**
- `Authorization: Bearer <TYPESAFE_API_KEY>`
- `Accept: application/json`

**Timeout:** 8 s (`config/forecast.php`). No hay reintentos.

```json
{
  "model": "<JEV_MODEL>",
  "state": { ... },
  "questions": {
    "direction": {
      "type": "choice",
      "instructions": "Which closing-price outcome is most likely at the target session compared with reference_close? Estimate the probabilities from the supplied data only; do not assume future market data is known.",
      "criteria": {
        "up":   "Target close is strictly greater than reference_close.",
        "down": "Target close is strictly less than reference_close.",
        "flat": "Target close equals reference_close exactly."
      }
    }
  }
}
```

### 1.2 Objeto `state`

Lo construye `ForecastStateBuilder::build()` ([app/Services/Forecast/ForecastStateBuilder.php](../app/Services/Forecast/ForecastStateBuilder.php)). `JevClient` le añade los dos últimos campos de la tabla.

| Campo | Contenido |
|---|---|
| `ticker` | Símbolo del instrumento, por ejemplo `NVDA`. |
| `as_of_date` | Fecha de la última barra disponible, o la fecha de corte en un backtest. |
| `reference_close` | Cierre de esa barra. Es la referencia para decidir sube, baja o plano. |
| `bars` | Hasta **252** barras diarias (`history_bars`), de la más antigua a la más reciente, con `date, open, high, low, close, volume`. |
| `indicators` | Indicator Snapshot de `as_of_date`: `sma20, sma50, sma200, ema21, ema55, rsi14, adx, macd, macd_signal, macd_hist, bb_upper, bb_middle, bb_lower, rvol`. Los valores que faltan van como `null`. |
| `signals` | Signals de esa fecha, `[{type, metadata}]`, ordenadas por tipo. |
| `horizon_sessions` | `1` o `5` (`horizons`). |
| `target_date` | La fecha que está `horizon_sessions` sesiones bursátiles por delante. Se calcula con el calendario NYSE de `config/ingestion.php`, sin fines de semana ni festivos. |

### 1.3 Condiciones previas: cuándo no se llama

No se envía nada si falta alguna de estas condiciones:

- Al menos **50 barras** de histórico (`minimum_bars`).
- Indicator Snapshot del día con `rsi14` y `sma50`.
- Cierre de referencia mayor que 0.
- `JEV_ENABLED=true` y `JEV_MARKET_DATA_RIGHTS_VERIFIED=true`.
- `TYPESAFE_API_KEY` informada y `JEV_MODEL` con el formato `jev-<versión>`.

### 1.4 Respuesta esperada y validación

Chartiko acepta la respuesta solo si cumple todo esto:

- `model` coincide con el modelo enviado.
- `answers.direction.type` es `choice`.
- `answers.direction.probabilities` contiene exactamente `up`, `down` y `flat`. Cada valor es un número entre 0 y 1, y la suma es 1 con una tolerancia de 0,0001.
- `answers.direction.choice` es la opción de mayor probabilidad.

Si falla cualquiera de estas comprobaciones, el resultado es `invalid_provider_response` y no se guarda nada. En los logs solo queda la clase de la excepción o el código HTTP.

### 1.5 Lo que no se envía a Jev

- **`minimumProbability`**, el umbral que elige el usuario. Se aplica después en `ForecastService`: hay señal `up` o `down` solo si la opción más probable no es `flat` y su probabilidad supera ese umbral.
- **`question_version`** (`close-direction-v1`). Solo forma parte de la clave de caché, que es instrumento + horizonte + modelo + versión de la pregunta + `as_of_date`. Si ya hay una predicción guardada para esa clave, no se vuelve a llamar a Jev. Un bloqueo de 30 s evita llamadas duplicadas simultáneas.
- **Datos personales.** Ninguno: solo se envían datos de mercado del instrumento.

### 1.6 Límites

- 10 peticiones por minuto (`requests_per_minute`), solo para Registered Users.

---

## 2. Ficha de empresa con IA

La llamada la hace un cliente genérico compatible con OpenAI Chat Completions, `OpenAiCompatibleClient::structured()` ([app/Services/Ai/OpenAiCompatibleClient.php](../app/Services/Ai/OpenAiCompatibleClient.php)). El esquema, el prompt y el contexto están en [app/Services/CompanyCard/](../app/Services/CompanyCard/) y la configuración en [config/ai_card.php](../config/ai_card.php). La operación del servicio se describe en [ai-company-card-operations.md](ai-company-card-operations.md).

En resumen:

1. Se pide salida estructurada con `response_format: json_schema` en modo `strict`, con el esquema `company_card_v2`.
2. Si el proveedor responde 400 a ese formato, se reintenta una vez con `json_object` y el esquema incluido en el prompt.
3. Laravel vuelve a validar la respuesta por su cuenta antes de guardarla.

### 2.1 Petición HTTP

`POST {AI_BASE_URL}/chat/completions`

**Cabeceras:**
- `Authorization: Bearer <AI_API_KEY>`
- `Accept: application/json`
- `Content-Type: application/json`

El cliente no sigue redirecciones.

```json
{
  "model": "<AI_MODEL>",
  "messages": [
    { "role": "system", "content": "<instrucciones company-card-prompt-v3>" },
    { "role": "user",   "content": "<JSON de contexto del instrumento>" }
  ],
  "response_format": {
    "type": "json_schema",
    "json_schema": { "name": "company_card_v2", "strict": true, "schema": { ... } }
  },
  "max_tokens": 8000,
  "temperature": 0.2
}
```

| Parámetro | Variable de entorno | Valor por defecto | Notas |
|---|---|---|---|
| `model` | `AI_MODEL` | — | Si no está configurado, no se llama. |
| `max_tokens` / `max_completion_tokens` | `AI_MAX_TOKENS`, `AI_MAX_TOKENS_PARAM` | 8000, `max_tokens` | Es 8000 porque el modelo `chat` razona antes de responder, y con menos tokens el JSON llegaba cortado. |
| `temperature` | `AI_TEMPERATURE` | 0.2 | Si está vacía, no se envía. |
| `response_format` | `AI_RESPONSE_FORMAT` | `json_schema` | Alternativa: `json_object`. |
| Reintento con `json_object` | `AI_RESPONSE_FORMAT_FALLBACK` | `true` | Solo si el proveedor responde 400. |
| Timeout | `AI_TIMEOUT_SECONDS` | 40 s | La conexión tiene un límite de 10 s. Queda por debajo de los 60 s de Nginx. |

No se envían `top_p`, `seed`, `tools` ni `stream`.

`AI_BASE_URL` tiene que usar `https://`. Solo se permite `http://` con `localhost` o `127.0.0.1`, para servidores LLM locales. No puede llevar usuario, query ni fragmento.

### 2.2 Cómo se pide la salida estructurada

**Modo `json_schema` (el normal).** El esquema `CompanyCardSchema::schema()` ([CompanyCardSchema.php](../app/Services/CompanyCard/CompanyCardSchema.php)) va dentro de `response_format` con `strict: true`. Todas las propiedades son obligatorias, no se admiten propiedades extra (`additionalProperties: false`) y los valores desconocidos se envían como `null`.

```
company     { name, ticker, sector|null, industry|null, description|null }
outlook     { horizon_months (siempre 3), stance: bullish|neutral|bearish,
              summary, macro_context, sector_context,
              drivers[1-5], risks[1-5], confidence: low|medium|high }
limitations string
sources     string[0-5]
```

- El esquema no tiene campos de precio objetivo, valor razonable, stop, rating ni posición.
- **Desde `company-card-v2` el esquema no incluye métricas.** El modelo no tiene datos financieros fiables, así que en la v1 dejaba casi todas a `null`. Ahora las cifras clave las calcula Chartiko con los informes 10-K/10-Q de la empresa en la SEC (EDGAR XBRL) y con el último cierre. Son estas: `pe_ttm`, `eps_diluted_ttm`, `market_cap_millions`, `revenue_ttm_millions`, `revenue_growth_yoy_percent`, `ebitda_millions`, `ebitda_margin_percent`, `net_debt_to_ebitda`, `roic_percent` y `dividend_yield_percent`.
- Cada cifra lleva su periodo y su fuente. Si no tiene sentido, lleva un motivo en lugar del valor: `negative_earnings`, `financial_sector`, `missing_data`…
- Las cifras se envían al modelo como contexto verificado (objeto `fundamentals` del mensaje `user`) y se muestran en la ficha tal cual, sin pasar por la IA. El detalle está en [specs/company-fundamentals-sec.md](specs/company-fundamentals-sec.md).

**Modo `json_object` (respaldo).** Se envía `{"type": "json_object"}` y se añade al mensaje `system` esta instrucción, seguida del esquema en JSON:

> Respond with exactly one JSON object (no Markdown, no prose) that validates against this JSON Schema named "company_card_v2". Every property is required and no other property is allowed:

**Cuándo se rechaza la respuesta.** Si `finish_reason` es distinto de `stop`, si `message.refusal` no está vacío, si el contenido no es JSON o si el JSON es una lista en vez de un objeto. Antes de leer el JSON se quita el envoltorio ```` ```json ```` si viene.

### 2.3 Mensajes

**`system`** (`CompanyCardPrompt`, versión `company-card-prompt-v3`, en [CompanyCardPrompt.php](../app/Services/CompanyCard/CompanyCardPrompt.php)). Incluye:

- La fecha de hoy y el idioma de salida: `es` → español de España, `en` → inglés.
- Las cifras financieras del objeto `fundamentals` están verificadas, salen de los informes ante la SEC y llevan su periodo. El modelo debe apoyarse en ellas y no inventar otras. Si no hay `fundamentals`, no debe dar ninguna cifra financiera y la previsión será solo cualitativa.
- `outlook` es una visión cualitativa a 3 meses. `stance` describe el escenario base, no es una recomendación.
- No dar precios objetivo, valores razonables, stops, ratings de compra/venta ni consejos de inversión.
- Límites de longitud en caracteres:

  | Campo | Límite |
  |---|---|
  | `description` | 600 |
  | `summary` | 700 |
  | `macro_context`, `sector_context` | 500 cada uno |
  | `limitations` | 400 |
  | Cada driver, riesgo o fuente | 200 |

- `company.ticker` debe coincidir con el ticker pedido.

**`user`** es solo el JSON que genera `CompanyCardContextBuilder` ([CompanyCardContextBuilder.php](../app/Services/CompanyCard/CompanyCardContextBuilder.php)). Ningún texto del usuario llega al proveedor.

```json
{
  "instrument": { "ticker": "NVDA", "company": "...", "sector": "...", "exchange": "..." },
  "fundamentals": { "period_end": "...", "metrics": { "pe_ttm": { "value": 0.0, "period": "..." }, "...": "..." } },
  "technical": {
    "as_of_date": "2026-10-15",
    "close": 0.0,
    "change_percent": 0.0,
    "indicators": { "sma20": 0.0, "sma50": 0.0, "sma200": 0.0, "rsi14": 0.0, "rvol": 0.0, "macd_hist": 0.0, "adx": 0.0 },
    "signals": ["golden_cross"],
    "chart_patterns": [{ "type": "...", "status": "...", "breakout_level": 0.0 }]
  }
}
```

El objeto `fundamentals` se envía cuando hay cifras de la SEC para la empresa. El bloque `technical` solo se envía si `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true`. Sin esa variable viajan la identidad pública del instrumento y, si existen, las cifras de la SEC (sin las que dependen del precio: PER, capitalización y rentabilidad por dividendo). Nunca se envían datos de usuarios, sesiones, Watchlist ni Saved Screeners.

### 2.4 Validación al recibir la respuesta

`CompanyCardSchema::validate()` revisa toda la respuesta, aunque el proveedor diga que respeta el esquema.

**Comprobaciones estrictas:**
- Claves exactas.
- El ticker coincide con el pedido.
- `horizon_months === 3`.
- Los enums tienen valores válidos.

**Recorte en vez de rechazo.** Si un texto libre supera su límite, se corta al final de una frase o palabra y se añade "…". Los identificadores, enums y la estructura siguen siendo estrictos.

**Si la validación falla**, la ficha no se guarda ni se muestra, y el intento cuenta para los límites diarios.

### 2.5 Cuándo se llama y límites

La llamada solo se hace cuando se cumplen estas condiciones:

- `AI_CARD_ENABLED=true`.
- `AI_BASE_URL` válida, `AI_API_KEY` y `AI_MODEL` configurados.

**Configuración de producción (decidida el 2026-10-16):**
- Proveedor: DevExpert Inference (`https://inference.devexpert.io/v1`), modelo `chat`.
- `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true`, así que se envía el bloque `technical`.
- La clave se instala desde el secreto de GitHub `AI_API_KEY` mediante `deploy/configure-ai-env.py`. El procedimiento está en [ai-company-card-operations.md](ai-company-card-operations.md).

**Caché y concurrencia.** Una ficha se reutiliza durante 7 días por instrumento e idioma (`AI_CARD_FRESHNESS_DAYS`). Un bloqueo evita que dos peticiones simultáneas generen la misma ficha.

**Límites:**

| Límite | Valor | Variable |
|---|---|---|
| Fichas por usuario y día | 10 | `AI_CARD_USER_DAILY_LIMIT` |
| Fichas totales por día UTC | 200 | `AI_CARD_DAILY_LIMIT` |
| Peticiones POST por minuto | 5 | `AI_CARD_REQUESTS_PER_MINUTE` |

**Registro.** Las generaciones se guardan en `ai_company_card_generations` y se borran a los 90 días. Los logs solo contienen la clase de excepción o el código HTTP, nunca la clave, el prompt ni la respuesta.

---

## 3. Screener en lenguaje natural

Un usuario registrado describe en español o inglés lo que busca y el modelo lo traduce a los filtros **existentes** del Screener (`ai-screener-natural-language`, especificación `docs/specs/ai-screener-natural-language.md`). Usa el mismo proveedor que la ficha (`AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`) a través de `new OpenAiCompatibleClient('ai_screener')`, con su propio interruptor y límites.

### 3.1 Petición HTTP

`POST {AI_BASE_URL}/chat/completions` con `Authorization: Bearer {AI_API_KEY}`, igual que la ficha. Cuerpo:

```json
{
  "model": "{AI_MODEL}",
  "messages": [
    {"role": "system", "content": "<instrucciones de screener-query-prompt-v1 en el idioma de la petición>"},
    {"role": "user", "content": "<texto del usuario, tal cual (3 a 300 caracteres)>"}
  ],
  "response_format": {"type": "json_schema", "json_schema": {"name": "screener_query_v1", "strict": true, "schema": {}}},
  "max_tokens": 800,
  "temperature": 0
}
```

Timeout de 20 s. Si el proveedor responde 400 a `json_schema`, se repite una vez con `json_object` y el esquema dentro del mensaje de sistema (mismo respaldo que la ficha).

### 3.2 Esquema `screener-query-v1`

Todas las claves son obligatorias y no se admiten otras. Los enums salen de `ScreenerFilterParser`, el mismo que valida `GET /api/screener`:

| Campo | Tipo |
|---|---|
| `signal` | lista de Signals conocidas (máx. 9) |
| `rsi_min`, `rsi_max` | número 0–100 o `null` |
| `min_rvol` | número 0–20 o `null` |
| `price_above_sma200` | booleano |
| `ma_cross` | `bullish`, `bearish` o `null` |
| `pattern` | lista de patrones conocidos (máx. 4) |
| `pattern_status` | `any`, `forming`, `confirmed` |
| `sort` | uno de los seis órdenes |
| `unsupported` | hasta 5 textos de 120 caracteres: lo que los filtros no pueden expresar |
| `explanation` | texto de hasta 300 caracteres |

### 3.3 Mensajes

El mensaje de sistema describe cada filtro con el vocabulario de la interfaz y del manual, y fija convenciones: «sobrevendidas» = `rsi_max` 30, «sobrecompradas» = `rsi_min` 70, «volumen alto» = `min_rvol` 1,5 (salvo que el usuario dé números). Pide poner todo lo demás (sectores, precios, fundamentales, fechas, noticias, consejos) en `unsupported`, no inventar filtros y escribir `explanation` en el idioma de la petición. El texto del usuario va **solo** en el mensaje de usuario. No se envía ningún dato de la cuenta, del Screener ni de mercado.

### 3.4 Validación al recibir la respuesta

`ScreenerQuerySchema::validate()` exige las claves exactas, tipos estrictos (un número o un booleano en texto se rechazan), rangos y enums, limpia `unsupported` y `explanation` (caracteres de control fuera, espacios colapsados) y después pasa los filtros por `ScreenerFilterParser`. Cualquier fallo es `503 invalid_provider_response` y no se aplica nada. No hay recorte: un texto demasiado largo también se rechaza.

### 3.5 Cuándo se llama, caché y límites

Solo con `AI_SCREENER_ENABLED=true` y el proveedor configurado (si no, `503 provider_not_configured` sin petición). Una petición idéntica (texto normalizado, idioma y versiones) se sirve desde la caché durante 24 h sin llamar al proveedor ni contar para los límites.

| Límite | Valor | Variable |
|---|---|---|
| Peticiones por minuto y usuario | 6 | `AI_SCREENER_REQUESTS_PER_MINUTE` |
| Peticiones por usuario en 24 h | 30 | `AI_SCREENER_USER_DAILY_LIMIT` |
| Peticiones totales en 24 h | 600 | `AI_SCREENER_DAILY_LIMIT` |

Cuentan las filas `ok` e `invalid` de `ai_screener_queries` de las últimas 24 horas.

**Registro y privacidad.** El texto de la petición **nunca** se guarda ni se registra. `ai_screener_queries` guarda solo metadatos (usuario, idioma, longitud, resultado, número de partes no admitidas, tokens, latencia, modelo y versiones) y se borra a los 90 días (`AI_SCREENER_LOG_RETENTION_DAYS`). La clave de caché es un hash SHA-256.

---

## 4. Comparativa rápida

| | Jev | Ficha IA | Screener en lenguaje natural |
|---|---|---|---|
| Endpoint | `https://api.typesafe.ai/v1/systemone` (fijo) | `{AI_BASE_URL}/chat/completions` (configurable) | El mismo que la ficha |
| Formato de salida | Pregunta `choice` con probabilidades `up/down/flat` | JSON Schema estricto `company_card_v2` (respaldo `json_object`) | JSON Schema estricto `screener_query_v1` (respaldo `json_object`) |
| Datos enviados | 252 barras, indicadores y Signals del instrumento | Identidad del instrumento. Contexto técnico solo con derechos verificados | El texto del usuario y el idioma |
| Activación | `JEV_ENABLED` + `JEV_MARKET_DATA_RIGHTS_VERIFIED` | `AI_CARD_ENABLED` (+ `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED` para `technical`) | `AI_SCREENER_ENABLED` |
| Timeout | 8 s | 40 s | 20 s |
| Caché | Por instrumento, horizonte, modelo, versión de pregunta y `as_of_date` | Por instrumento e idioma, 7 días | Por hash del texto normalizado, idioma y versiones, 24 h |
| Validación propia | Modelo, tipo, 3 probabilidades que suman 1, `choice` coherente | Esquema completo, ticker, enums y recorte de texto. Las cifras no vienen del modelo: las calcula Chartiko desde la SEC | Esquema completo, rangos, enums y el mismo parser que `GET /api/screener`, sin recorte |
| Datos personales | Ninguno | Ninguno | Texto libre del usuario (no se guarda; la política pide no incluir datos personales) |
