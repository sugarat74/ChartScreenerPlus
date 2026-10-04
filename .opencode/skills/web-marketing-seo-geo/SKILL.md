---
name: web-marketing-seo-geo
description: >-
  Prepara un sitio para promoción y marketing, audita e implementa mejoras SEO,
  contenido y conversión, y mejora GEO (comprensión y citación por motores
  generativos) y accesibilidad para agentes. Úsala para preparar un lanzamiento
  u optimizar visibilidad orgánica. No implica publicar campañas ni comprar anuncios.
---

# Marketing web, SEO y GEO

Entrega mejoras verificadas en el sitio, piezas promocionales respaldadas por hechos
y un plan medible de lanzamiento. Distingue SEO (rastreo/indexación), GEO
(comprensión/elegibilidad para respuestas generativas) y navegación por agentes.

## Alcance

Identifica producto real, público, idioma/mercado, conversión principal, dominio,
entorno, stack y accesos. Lee instrucciones, brief, arquitectura, diseño si cambia
UI y modelo de acceso del proyecto. Revisa estado actual, no solo informes antiguos.
Pregunta solo por datos que cambien materialmente el resultado; continúa lo demás.
Sin dominio, prepara configuración para recibirlo: no publiques canonicals ni
sitemaps con localhost, hosts inventados o placeholders.

- Auditar/planificar: inspección e informe; no cambios de producto.
- Preparar/mejorar/implementar: inspecciona, implementa cambios locales pertinentes
  y verifica. No termines solo con recomendaciones si se pidió implementación.
- Materiales/campañas: redacta las piezas solicitadas. Publicación, envíos, gasto,
  cuentas externas y despliegue requieren autorización específica, que puede
  existir ya en la conversación. Invocar la skill no autoriza contactar terceros.

Lee [references/checklist.md](references/checklist.md) para seleccionar controles.
Consulta [references/sources.md](references/sources.md) si una decisión depende de
políticas de motores/crawlers, métricas o estándares actuales. Comprueba fuentes
primarias vigentes y registra fecha/enlace. Sin red o acceso, marca lo pendiente;
no inventes volumen de búsqueda, rankings, tráfico ni resultados de herramientas.

## Ejecución

1. Mapea rutas: intención, público, contenido, CTA, indexabilidad y canonical.
   Conserva autorización y privacidad de portal/admin/recursos de usuarios.
2. Captura baseline: HTML inicial y renderizado, HTTP, metadatos, robots/sitemap,
   semántica, JSON-LD, navegación y rendimiento con herramientas disponibles.
   Diferencia lectura de código, comprobación local y medición en producción.
3. Prioriza bloqueos de rastreo/renderizado y duplicados; propuesta de valor y
   contenido; comprensión por agentes; rendimiento y medición. Implementa la
   mínima solución mantenible en el stack actual, sin migración SSR por defecto.
4. Prepara copy, CTAs con destino funcional y materiales/canales acordes al encargo;
   añade calendario, responsable propuesto y experimentos medibles. No publiques
   contenido masivo de poco valor ni inventes funcionalidades, precios, clientes,
   reviews, estadísticas, certificaciones o beneficios económicos.
5. Ejecuta verificaciones del repo y comprueba los comportamientos afectados:
   metadatos por ruta, HTML servido, sitemap, JSON-LD, previews y navegación.
   Las herramientas fallidas dejan resultados no verificados, no favorables.
6. Usa [assets/launch-report.md](assets/launch-report.md) para entregar cambios,
   pruebas y pendientes. Guarda el informe fuera de artefactos públicos y actualiza
   el seguimiento requerido. No marques indexación/citación por un build correcto.

## GEO y agentes

Haz extractable el contenido importante como texto: identidad del producto,
destinatario, capacidades, metodología, límites, fuentes y fechas reales. Enlaza
afirmaciones verificables a evidencia. JSON-LD debe coincidir con lo visible.
Evita cloaking, testimonios falsos, spam de enlaces e instrucciones ocultas para
manipular respuestas de agentes.

Distingue bots de búsqueda, recuperación, entrenamiento y visitas solicitadas por
usuarios. Consulta políticas oficiales de los proveedores pertinentes; no habilites
todos los crawlers ni cambies una política explícita de entrenamiento por GEO.

`llms.txt` es opcional/experimental, solo con propósito/mantenimiento claros o si
se solicita. No garantiza consumo, indexación ni citación; no es control de acceso.
No añadas MCP, APIs públicas o privilegios de agentes para resolver visibilidad.

Para navegación por agentes, comprueba enlaces HTML, labels, botones semánticos,
estados/errores y consecuencias claras de acciones. Da texto equivalente a gráficos.
No retires login/CSRF ni ejecutes acciones destructivas mediante GET.

## ChartScreenPlus / Chartiko

- Marca pública: Chartiko, dominio `https://www.chartiko.com`; omite EOD en copy público fuera de Admin sin cambiar el contrato de datos.
- Usa CONTEXT.md y las capacidades implementadas: Screener, Candidates, Signals,
  charts y EOD. No anuncies real-time, órdenes, backtesting, alertas, planes de pago
  o AI Copilot como existentes; no prometas rentabilidad.
- Trabaja en frontend/ y Laravel; alphapulse/ es referencia visual. Sigue DESIGN.md
  y PowerShell 5.1. No expongas engine, APIs privadas, logs ni informes internos.
- Separa captación pública de portal/admin. Indexa charts de Instruments solo con
  contenido público suficiente; evita cientos de páginas vacías o duplicadas.
- Contextualiza canvas con Instrument, fecha EOD, metodología y límites de Signals;
  no inventes stop/target. robots/noindex no sustituyen auth.
- Si hay bloqueos de publicación conocidos, continúa mejoras locales y regístralos;
  no despliegues para medir SEO sin autorización.

## Cierre

Entrega «preparado localmente», «validación publicada pendiente» o «bloqueado para
lanzamiento», según evidencia. Termina los cambios autorizados, piezas y pruebas;
deja acciones concretas para pendientes externos. No confundas Lighthouse, sitemap
o metadatos válidos con tráfico, conversiones o citaciones garantizados.
