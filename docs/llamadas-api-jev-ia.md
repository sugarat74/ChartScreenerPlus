# Llamadas a proveedores externos: Jev y API de IA

Este documento describe las dos llamadas que Chartiko hace a proveedores externos: el pronóstico **Jev** (TypeSafe) y la **ficha de empresa con IA** (cualquier API compatible con OpenAI Chat Completions).

Refleja el código a 2026-10-16. Si hay diferencias, la referencia es el código: `JevClient`, `ForecastStateBuilder` y `config/forecast.php` para Jev; `OpenAiCompatibleClient`, `app/Services/CompanyCard/*` y `config/ai_card.php` para la ficha. Si cambias la pregunta, el esquema o el prompt, sube su versión (`question_version`, `schema_version`, `prompt_version`) y actualiza este documento.

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

1. Se pide salida estructurada con `response_format: json_schema` en modo `strict`, con el esquema `company_card_v1`.
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
    { "role": "system", "content": "<instrucciones company-card-prompt-v2>" },
    { "role": "user",   "content": "<JSON de contexto del instrumento>" }
  ],
  "response_format": {
    "type": "json_schema",
    "json_schema": { "name": "company_card_v1", "strict": true, "schema": { ... } }
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
metrics     { pe_ttm, pe_forward, roic_percent, ebitda_millions,
              ebitda_margin_percent, revenue_growth_yoy_percent,
              net_debt_to_ebitda, market_cap_millions, dividend_yield_percent }
              cada métrica: { value: number|null, currency: string|null, period: string|null }
outlook     { horizon_months (siempre 3), stance: bullish|neutral|bearish,
              summary, macro_context, sector_context,
              drivers[1-5], risks[1-5], confidence: low|medium|high }
data_as_of  string|null
limitations string
sources     string[0-5]
```

- El esquema no tiene campos de precio objetivo, valor razonable, stop, rating ni posición.
- La unidad la fija el nombre de la métrica:
  - ratios (`pe_*`, `net_debt_to_ebitda`): en veces;
  - `*_percent`: en porcentaje (5.2 significa 5,2 %);
  - `*_millions`: en millones de la divisa indicada en `currency` (código ISO 4217).

**Modo `json_object` (respaldo).** Se envía `{"type": "json_object"}` y se añade al mensaje `system` esta instrucción, seguida del esquema en JSON:

> Respond with exactly one JSON object (no Markdown, no prose) that validates against this JSON Schema named "company_card_v1". Every property is required and no other property is allowed:

**Cuándo se rechaza la respuesta.** Si `finish_reason` es distinto de `stop`, si `message.refusal` no está vacío, si el contenido no es JSON o si el JSON es una lista en vez de un objeto. Antes de leer el JSON se quita el envoltorio ```` ```json ```` si viene.

### 2.3 Mensajes

**`system`** (`CompanyCardPrompt`, versión `company-card-prompt-v2`, en [CompanyCardPrompt.php](../app/Services/CompanyCard/CompanyCardPrompt.php)). Incluye:

- La fecha de hoy y el idioma de salida: `es` → español de España, `en` → inglés.
- Usar solo datos fiables y dar la cifra más reciente conocida con su periodo. Usar `null` solo cuando no se conoce la cifra, sin adivinar ni extrapolar.
- Las unidades de cada métrica.
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
  | Periodos y `data_as_of` | 40 |

- `company.ticker` debe coincidir con el ticker pedido.

**`user`** es solo el JSON que genera `CompanyCardContextBuilder` ([CompanyCardContextBuilder.php](../app/Services/CompanyCard/CompanyCardContextBuilder.php)). Ningún texto del usuario llega al proveedor.

```json
{
  "instrument": { "ticker": "NVDA", "company": "...", "sector": "...", "exchange": "..." },
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

El bloque `technical` solo se envía si `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true`. Sin esa variable solo viaja la identidad pública del instrumento. Nunca se envían datos de usuarios, sesiones, Watchlist ni Saved Screeners.

### 2.4 Validación al recibir la respuesta

`CompanyCardSchema::validate()` revisa toda la respuesta, aunque el proveedor diga que respeta el esquema.

**Comprobaciones estrictas:**
- Claves exactas.
- El ticker coincide con el pedido.
- `horizon_months === 3`.
- Los enums tienen valores válidos.
- Límites numéricos: ±1000 % en porcentajes y ±10.000.000 en el resto.
- Las métricas en millones llevan un código ISO válido. En ratios y porcentajes la divisa se descarta.

**Recorte en vez de rechazo.** Si un texto libre supera su límite, se corta al final de una frase o palabra y se añade "…". Los identificadores, periodos, enums, números y la estructura siguen siendo estrictos.

**Si la validación falla**, la ficha no se guarda ni se muestra, y el intento cuenta para los límites diarios.

### 2.5 Cuándo se llama y límites

La llamada solo se hace cuando se cumplen estas condiciones:

- `AI_CARD_ENABLED=true`.
- `AI_BASE_URL` válida, `AI_API_KEY` y `AI_MODEL` configurados.

**Estado a 2026-10-16:** desactivada en producción.

**Caché y concurrencia.** Una ficha se reutiliza durante 7 días por instrumento e idioma (`AI_CARD_FRESHNESS_DAYS`). Un bloqueo evita que dos peticiones simultáneas generen la misma ficha.

**Límites:**

| Límite | Valor | Variable |
|---|---|---|
| Fichas por usuario y día | 10 | `AI_CARD_USER_DAILY_LIMIT` |
| Fichas totales por día UTC | 200 | `AI_CARD_DAILY_LIMIT` |
| Peticiones POST por minuto | 5 | `AI_CARD_REQUESTS_PER_MINUTE` |

**Registro.** Las generaciones se guardan en `ai_company_card_generations` y se borran a los 90 días. Los logs solo contienen la clase de excepción o el código HTTP, nunca la clave, el prompt ni la respuesta.

---

## 3. Comparativa rápida

| | Jev | Ficha IA |
|---|---|---|
| Endpoint | `https://api.typesafe.ai/v1/systemone` (fijo) | `{AI_BASE_URL}/chat/completions` (configurable) |
| Formato de salida | Pregunta `choice` con probabilidades `up/down/flat` | JSON Schema estricto `company_card_v1` (respaldo `json_object`) |
| Datos enviados | 252 barras, indicadores y Signals del instrumento | Identidad del instrumento. Contexto técnico solo con derechos verificados |
| Activación | `JEV_ENABLED` + `JEV_MARKET_DATA_RIGHTS_VERIFIED` | `AI_CARD_ENABLED` (+ `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED` para `technical`) |
| Timeout | 8 s | 40 s |
| Caché | Por instrumento, horizonte, modelo, versión de pregunta y `as_of_date` | Por instrumento e idioma, 7 días |
| Validación propia | Modelo, tipo, 3 probabilidades que suman 1, `choice` coherente | Esquema completo, ticker, enums, límites, divisas, recorte de texto |
| Datos personales | Ninguno | Ninguno |
