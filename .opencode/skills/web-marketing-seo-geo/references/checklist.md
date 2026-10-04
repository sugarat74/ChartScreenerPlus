# Checklist de lanzamiento

Selecciona controles pertinentes y registra verificado/requiere cambio/no verificado
con archivo/línea o URL/prueba. No aplica necesita motivo. Separa local/producción.

| ID | Control | Evidencia de cierre |
|---|---|---|
| MKT-01 | Público, problema, beneficio, diferenciación y conversión | Copy factual y CTA funcional |
| MKT-02 | Landing, ejemplos, confianza, preguntas reales | Contenido útil sin métricas/testimonios inventados |
| MKT-03 | Kit promocional solicitado por canal | Piezas, destinos y derechos de assets identificados |
| MKT-04 | Calendario y experimentos | Responsable, métrica/plazo y estado de publicación |
| SEO-01 | HTTP/HTTPS, redirects, indexación y errores | Status y contenido correctos, 404/410 real para inexistentes |
| SEO-02 | HTML inicial/renderizado y enlaces | Texto/metadatos accesibles al crawler objetivo, href reales |
| SEO-03 | Títulos/descriptions/idioma/headings | Específicos y semánticos, sin keyword stuffing |
| SEO-04 | Canonical, facetas, parámetros y UTM | Política para duplicados sin canonicalizar contenido diferente |
| SEO-05 | robots, sitemap y staging | Solo URLs canónicas públicas 200 indexables; lastmod real |
| SEO-06 | JSON-LD apropiado | JSON válido y coherente con contenido visible |
| SEO-07 | Imágenes y OG/Twitter por ruta | URLs públicas absolutas, alt útil, preview correcto |
| SEO-08 | Rendimiento, móvil, accesibilidad | Medición reproducible, sin confundir laboratorio y campo |
| GEO-01 | Identidad y hechos extractables | Marca coherente, resumen claro y URLs estables |
| GEO-02 | Respuestas útiles con sustento | Definiciones/pasos/límites/fuentes y fechas reales |
| GEO-03 | Políticas de crawlers | Matriz búsqueda/recuperación/entrenamiento/usuario vigente |
| GEO-04 | Lectura/navegación por agentes | Texto fuera de canvas, labels, estados y enlaces operables |
| GEO-05 | Opcionales como llms.txt | Propósito/consumidor/mantenimiento, sin promesas |
| MEAS-01 | SEO/adquisición/conversión | Baseline/fuente y seguimiento landing→conversión |
| MEAS-02 | Citas generativas | Consultas no sesgadas, idioma, motor/modelo, fecha y URLs citadas |
| MEAS-03 | Privacidad y publicación | Analítica mínima sin secretos/PII; respeta política/consentimiento |

## Decisiones delicadas

- Disallow puede impedir leer noindex. No combines ambos sin analizar efecto.
  Privados requieren auth; no los listes en sitemap/llms.txt ni reveles rutas
  sensibles mediante robots como supuesto control de seguridad.
- SSR/SSG/prerender se eligen por contenido, coste y stack; prueba el artefacto
  servido. No asumas JavaScript en todos los bots ni sirvas hechos distintos a bots.
- Evita trampas de facetas y páginas masivas de poca utilidad. FAQ visible puede
  ayudar sin ser elegible para rich result FAQPage; consulta requisitos vigentes.
- No simules ratings, entidades o categorías comerciales en schema. JSON válido
  no acredita elegibilidad para resultados enriquecidos.
- Search Console/Bing/analytics solo son evidencia si se consultaron. `site:` o
  un chat manual no prueban indexación completa; ausencia de cita no prueba ausencia
  de rastreo. Repite consultas comparables y no atribuyas causalidad a un único cambio.
- No envíes URLs privadas a verificadores externos. UTM no debe incluir datos
  personales ni multiplicar canonicals/sitemaps. Sin cuentas externas, prepara
  configuración/piezas y documenta el acceso pendiente.
