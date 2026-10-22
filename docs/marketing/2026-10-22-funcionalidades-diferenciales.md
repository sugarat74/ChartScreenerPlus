# Funcionalidades diferenciales de Chartiko

- Fecha y zona: 2026-10-22, Europe/Madrid.
- Alcance: ideas de producto para diferenciarse de la competencia, válidas en español y en inglés. **No están aprobadas ni en el alcance del MVP.** Cada una necesita su spec en `docs/specs/` y una decisión explícita del propietario antes de implementarse (ver *MVP Scope Guard* en `AGENTS.md`).
- Relacionado: `2026-10-22-plan-lanzamiento.md` (posicionamiento y canales).

## Contexto competitivo

Detectar patrones chartistas, por sí solo, **no diferencia**. Varios competidores ya lo hacen (datos de memoria a 2026-10; verificar en sus webs antes de usarlos en comunicación):

| Herramienta | Detección de patrones | Limitación aprovechable |
|---|---|---|
| Finviz | Filtro *Pattern*: doble techo/suelo, H-C-H, triángulos, cuñas, canales | Sin taza con asa; sin explicación ni seguimiento |
| TrendSpider | Reconocimiento automático de patrones chartistas y velas | Caja negra; caro |
| MarketSmith (IBD) | Taza con asa, doble suelo, bases planas (CANSLIM) | ~150 $/mes; solo inglés |
| Autochartist / Trading Central | Detección automática integrada en brokers | Genérico; sin producto propio para el usuario final |
| Tickeron | Patrones con "probabilidad de éxito" | Marketing de IA que genera desconfianza |
| TradingView | Indicadores de patrones automáticos (según plan) y de la comunidad | Alcance variable; no es su foco |

**Lo que Chartiko tiene y es difícil de copiar en conjunto:** detección determinista y calibrada, backtesting, fundamentales verificados de la SEC (EDGAR), ficha de empresa con IA, alertas sobre la Watchlist.

**Mensaje diferencial propuesto:** *"Patrones que puedes seguir de principio a fin, con su historial real a la vista."*

## Ideas

Esfuerzo: **B** bajo (días), **M** medio (1–3 semanas), **A** alto (más de un mes).

### Las tres más diferenciales

| # | Idea | Qué es | Por qué diferencia | Esfuerzo |
|---|---|---|---|---|
| 1 | **Registro público de detecciones** | Cada patrón detectado queda registrado con fecha, niveles y resultado a 5/20/60 sesiones. Público y sin filtrar: también los fallos. | Nadie publica su historial real. Argumento de marketing ("enseñamos nuestros fallos") y contenido y páginas SEO diarios. | M. Requiere licencia de datos para mostrarlo en público. |
| 2 | **Ciclo de vida del patrón + alertas de transición** | Estados *en formación → confirmado (ruptura con volumen) → objetivo técnico alcanzado / invalidado*, con alerta en cada cambio. | La competencia muestra el patrón terminado o solo cuando aparece. Seguirlo hasta su resolución retiene. | M |
| 3 | **Casos históricos similares** | "Gráficos que se parecían a este": formaciones parecidas (patrón y contexto) y la distribución de sus resultados (peor caso, mediana, mejor caso). | Estadística descriptiva honesta frente a "IA que predice". | A. Necesita índice de similitud e histórico amplio. |

### Contexto

| # | Idea | Qué es | Esfuerzo |
|---|---|---|---|
| 4 | **Puntuación de calidad del patrón** | Checklist puntuado: a favor de la tendencia, confirmación de volumen, fuerza relativa frente al índice y al sector, régimen de mercado (% del S&P 500 sobre la media de 200). No predice; muestra qué falla. | M |
| 5 | **Técnico + fundamental verificado** | Filtros como "taza con asa + crecimiento de ingresos >20 % (SEC) + márgenes al alza". Lo que vende MarketSmith, a una fracción del precio. | M |
| 6 | **Aviso de resultados próximos** | "Presenta resultados en 4 sesiones": riesgo que puede invalidar cualquier patrón. | B |
| 7 | **Confluencia diario/semanal** | Mismo patrón o nivel en gráfico diario y semanal; filtro de alta convicción. Encaja con los datos de cierre actuales. | M |
| 8 | **Patrones por sector** | "4 semiconductoras forman el mismo patrón": rotación sectorial agrupando detecciones. Buen contenido semanal. | B–M |

### Explicabilidad

| # | Idea | Qué es | Esfuerzo |
|---|---|---|---|
| 9 | **"Por qué sí / por qué no" y casi-patrones** | Cada regla del patrón con ✓/✗ sobre el gráfico. Casi-patrones: "solo le falta romper la línea clavicular, a +2,3 %", con alerta "avísame cuando se complete". | M |

### Retención y crecimiento

| # | Idea | Qué es | Esfuerzo |
|---|---|---|---|
| 10 | **Modo entrenamiento** | Gráfico histórico real con el futuro oculto: "¿qué patrón ves?, ¿hacia dónde irá?". Se revela el resultado, con puntuación y rachas. Educativo, viral y fuente de vídeo corto. | M |
| 11 | **Diario de seguimiento** | "Sigo este patrón": registra cómo evoluciona y la tasa personal de lectura correcta. Sin órdenes ni consejos. | M |
| 12 | **"Tu semana" redactado por IA** | Resumen en lenguaje natural de la Watchlist (patrones nuevos, confirmados o invalidados, resultados próximos). La IA solo redacta hechos calculados de forma determinista. | B–M |
| 13 | **Tarjetas compartibles y widget para creadores** | Imagen del patrón con marca, lista para X o Telegram, y widget embebible. Cada vez que se comparte llega a gente nueva; encaja con afiliados. | M |

### Usuarios avanzados

| # | Idea | Qué es | Esfuerzo |
|---|---|---|---|
| 14 | **Fórmulas propias escritas por IA** | Lenguaje de expresiones propio (nivel 1, ver abajo): `sma(close, 20) cruza_arriba sma(close, 50) and rsi(14) < 35`. El usuario describe la condición en lenguaje natural, la IA genera la fórmula, el usuario la **ve y puede editarla**, y la ejecuta el motor determinista. Usable en screener, gráfico y alertas. Evolución de `ai-screener-natural-language`; candidata al plan Premium. | M (nivel 1) |

Niveles posibles del lenguaje:

| Nivel | Alcance | Estimación | Decisión |
|---|---|---|---|
| 1. Expresiones | Combinar indicadores existentes, comparaciones, cruces, operaciones aritméticas | 3–6 semanas | Recomendado, con IA encima |
| 2. Indicadores propios | Variables, `highest`/`lowest`, series derivadas, `plot()` en el gráfico | 2–3 meses | Solo si lo piden los usuarios de pago |
| 3. Lenguaje completo tipo Pine Script | Bucles, estado, funciones, estrategias | 6–12+ meses | Descartado: es el terreno de TradingView |

Notas técnicas:

- **Seguridad:** nunca `eval` ni ejecutar Python del usuario. Parser propio (por ejemplo Lark) + intérprete con una lista cerrada de funciones, y límites de complejidad, de periodo máximo hacia atrás y de tiempo de ejecución.
- **Cálculo:** el screener actual filtra por SQL sobre `screener_rows` precalculadas, y una fórmula de usuario no se puede prefiltrar así. Hay que calcularla al vuelo sobre el universo; es viable con 500 valores si se vectoriza con numpy (el motor usa hoy listas de Python en `engine/app/indicators/core.py`). Las fórmulas guardadas se calculan en la ejecución diaria y se cachean. El coste crece con usuarios × fórmulas × universo al ampliar mercados.
- **Experiencia de usuario:** suele ser la parte más costosa: editor con autocompletado, errores comprensibles en español e inglés, documentación, ejemplos y vista previa sobre un gráfico.
- **Diferencial:** el lenguaje solo no diferencia (TradingView, TrendSpider). Lo que diferencia es la IA que escribe una regla **transparente y reproducible**, coherente con el mensaje de "reglas claras".

### Contexto macro

| # | Idea | Qué es | Esfuerzo |
|---|---|---|---|
| 15 | **Panel de contexto de mercado** | Página `/mercado` dentro de Chartiko (no como app aparte): semáforo de entorno de mercado con reglas visibles, sectores (ETFs SPDR) por fuerza relativa, amplitud del S&P 500, tipos y curva, liquidez neta de la Fed, bancos centrales, datos adelantados públicos (FRED) y ratio put/call de opciones (Cboe, pendiente de permiso). Se conecta con los patrones: cada patrón muestra la tendencia de su sector y el entorno. **Planificada** como 6 features; spec: `docs/specs/market-context.md`. Descartado por coste o licencia: sentimiento en X, LEI de Conference Board, ISM, FedWatch, déficits. | M (6 features, ~6–8 semanas en total) |

## Priorización propuesta

| Orden | Ideas | Motivo |
|---|---|---|
| 1 | Ciclo de vida + alertas de transición (2) y casi-patrones (9) | Mejoran lo que ya se vende (patrones y alertas) y la retención; esfuerzo medio. |
| 2 | Registro público de detecciones (1) | El argumento más difícil de copiar. Necesita meses de datos: **empezar a registrar ya**, aunque se publique más tarde. |
| 3 | Técnico + fundamental SEC (5) y aviso de resultados (6) | Aprovechan datos existentes; el aviso de resultados se hace en días. |
| 4 | Modo entrenamiento (10) y tarjetas compartibles (13) | Captación y contenido para los canales del plan de lanzamiento. |
| Después | Casos similares (3), puntuación (4), sectores (8), diario (11), "Tu semana" (12), confluencia (7) | Mucho valor, pero más caras o menos urgentes. |
| Después | Fórmulas propias escritas por IA (14) | Para una minoría de usuarios avanzados; encaja en Premium cuando los patrones estén consolidados. |

**Advertencia:** no abrir demasiados frentes a la vez. Con la 2, la 9 y el registro de la 1 ya hay un argumento que no tiene ningún competidor.

## Límites que deben respetar todas las ideas

- Nunca precios objetivo, recomendaciones, rentabilidades prometidas ni "probabilidad de éxito". Solo estadística descriptiva y reglas visibles.
- Datos de cierre (EOD); nada en tiempo real.
- Publicar resultados o datos de mercado requiere la licencia de datos (R1 del plan de lanzamiento) y revisar el riesgo regulatorio (R6).
