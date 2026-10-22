# Plan de marketing de lanzamiento de Chartiko

- Fecha y zona: 2026-10-22, Europe/Madrid.
- Alcance: plan de trabajo. No se ha publicado ninguna campaña, cuenta ni anuncio.
- Presupuesto: **~100 €/mes** en medios pagados. Herramientas en plan gratuito o autoalojadas.
- Mercado: inversor minorista hispanohablante (España primero, Latinoamérica después) con conocimientos técnicos básicos o intermedios.
- Precio de referencia (propuesto, no implementado): Gratis + Pro a 9,99 €/mes o 99 €/año, con precio de fundador para los primeros 100 clientes.
- Los costes, CPC y precios de terceros son **orientativos**. Hay que confirmarlos en cada plataforma antes de gastar.

## 1. Posicionamiento

**Mensaje principal:**

> Patrones chartistas detectados con reglas claras, explicados en español y con su historial real, sobre las acciones que sigues.

| Pilar | Qué demuestra | Contra quién diferencia |
|---|---|---|
| En español y explicado | Manual visual, cada Signal y patrón dice por qué se detectó | Finviz, TrendSpider, MarketSmith (inglés) |
| Reglas transparentes | Detección determinista y calibrada, no "caja negra de IA" | Tickeron, TrendSpider |
| Historial honesto | Estadística descriptiva por patrón (requiere hacer pública la estadística del backtesting) | Herramientas que prometen "probabilidad de éxito" |
| Te avisa | Alertas de patrones sobre tu Watchlist, digest diario | Screeners que hay que abrir a mano |
| Asequible | 9,99 €/mes frente a 40–150 $/mes | MarketSmith, TrendSpider, Finviz Elite |

Ideas de producto que refuerzan estos pilares: `2026-10-22-funcionalidades-diferenciales.md`.

**Tono:** educativo y sobrio. Nunca "gana dinero", "señales ganadoras", "acierto del X %" ni rentabilidades. Siempre "análisis técnico, no recomendación".

## 2. Requisitos antes de lanzar

No conviene gastar dinero en adquisición hasta cerrar esto:

| # | Requisito | Por qué |
|---|---|---|
| R1 | Fuente de datos con licencia de visualización a terceros | Sin ella no se puede cobrar, ni publicar capturas de gráficos en redes con seguridad. Ver `docs/risks-and-open-questions.md`. |
| R2 | Pagos (Stripe, Paddle o Lemon Squeezy) y planes Gratis/Pro | Paddle y Lemon Squeezy actúan como *merchant of record* y gestionan el IVA de la UE (OSS). |
| R3 | Analítica respetuosa: Umami o Plausible autoalojado en el VPS | Embudo medible sin depender de cookies de terceros. |
| R4 | SEO base resuelto (`robots.txt`, `sitemap.xml`, soft-404) | Pendientes SEO-01/SEO-05 en `2026-10-04-seo-geo.md`; verificar estado. |
| R5 | Digest de alertas por email en producción | Plan `alerts-email-golive` (Brevo). Es la pieza central de retención. |
| R6 | Revisión legal de comunicaciones | Publicar listas de acciones "con patrón" puede tratarse como recomendación de inversión (Reglamento MAR / CNMV). Avisos, sin lenguaje de recomendación y, si es posible, consulta con un asesor. Consentimiento RGPD con doble opt-in para emails; consentimiento de cookies para cualquier píxel publicitario. |

## 3. Canales

Prioridad según coste, encaje con el público y esfuerzo para una sola persona.

| Prioridad | Canal | Uso | Coste | Frecuencia |
|---|---|---|---|---|
| ★★★ | **SEO programático** | Páginas públicas por patrón ("qué es un doble suelo") y por ticker y patrón ("AAPL: patrones detectados"). Tráfico que se acumula con el tiempo. | 0 € (tiempo de desarrollo) | Continuo |
| ★★★ | **Email (Brevo)** | Lista de espera, onboarding, digest semanal "Radar Chartiko". Canal propio, no depende de algoritmos. | 0 € (plan gratuito, ~300 emails/día) | Semanal y transaccional |
| ★★★ | **X (#fintwit en español)** | *Build in public* del fundador, hilos educativos sobre patrones, gráficos explicados. Ahí está el trader técnico hispano. | 0 € | 1 post/día, 1 hilo/semana |
| ★★★ | **Telegram** | Canal gratuito con el resumen diario de patrones detectados (formato educativo) y enlace a la app. Muy usado por traders hispanos. | 0 € | Diario (automatizable desde el producto) |
| ★★ | **Rankia** | Foro de inversión de referencia en España. Participación genuina; un hilo de presentación si las normas lo permiten. | 0 € | 2–3 aportes/semana |
| ★★ | **Vídeo corto** (YouTube Shorts, Instagram Reels, TikTok) | "Patrón de la semana en 45 s": grabación de pantalla de Chartiko con voz. El mismo vídeo sirve para las tres plataformas. | 0 € (CapCut/Canva gratis) | 2/semana |
| ★★ | **YouTube largo** | Tutoriales de 8–12 min: "Cómo encontrar tazas con asa en el S&P 500". Buen SEO en YouTube. | 0 € | 2/mes |
| ★ | **Microinfluencers y newsletters** de bolsa en español (5k–50k seguidores) | Mención patrocinada o afiliación recurrente. | 50–150 € por mención (orientativo) | 1 al mes, como experimento |
| ★ | **Product Hunt / Hacker News** | Solo con la versión en inglés y más mercados. No es el público objetivo inicial. | 0 € | Una vez, más adelante |
| ✗ | Google Search Ads | Los CPC de términos de bolsa son altos: con 100 € salen pocas decenas de clics. Además, la categoría financiera puede requerir verificación. | — | No usar al inicio |
| ✗ | X Ads, LinkedIn Ads, TikTok Ads | Poco rendimiento con presupuestos pequeños, mínimos diarios altos o restricciones para productos financieros. | — | No usar al inicio |

## 4. Anuncios y campañas pagadas (100 €/mes)

Regla: **lo pagado solo amplifica lo que ya funciona de forma orgánica.** Nunca se crea un anuncio desde cero sin haberlo probado antes como contenido gratuito.

### Reparto mensual

| Mes | Partida | Importe | Detalle |
|---|---|---|---|
| Pre-lanzamiento | — | 0 € | Se reserva el presupuesto; solo contenido orgánico y lista de espera. |
| Mes 1 (lanzamiento) | Meta Ads (Instagram/Facebook) | 70 € | Promocionar los 2 Reels con mejor retención orgánica. España, 25–55 años, intereses bolsa/trading/análisis técnico. Objetivo: tráfico a la página de registro. |
| | Retargeting Meta | 30 € | Visitantes que no se registraron (requiere píxel con consentimiento). |
| Mes 2 | Microinfluencer o newsletter | 50–80 € | Una mención con código de precio de fundador para medir la conversión. |
| | Meta Ads | 20–50 € | El mejor creativo del mes 1. |
| Mes 3 en adelante | Lo que haya dado menor coste por cliente de pago | 100 € | Se elimina el canal perdedor. |

### Formatos de anuncio

1. **Reel demostrativo (15–30 s):** "Así detecta Chartiko un doble suelo en 3 segundos". Pantalla real, subtítulos, llamada a la acción "Pruébalo gratis".
2. **Carrusel educativo:** "5 patrones chartistas que todo trader debería reconocer". Última diapositiva: "Chartiko los busca por ti en todo el S&P 500".
3. **Antes/después:** "Revisar 500 gráficos a mano / Ver solo los que cumplen tu patrón".
4. **Precio de fundador (retargeting):** "9,99 € → 6,99 €/mes de por vida para los 100 primeros".

### Métricas de corte

Valores orientativos; se ajustan con los datos del primer mes.

| Métrica | Seguir | Parar |
|---|---|---|
| CPC (Meta) | < 0,40 € | > 1 € |
| Clic → registro | > 15 % | < 5 % |
| Coste por registro | < 3 € | > 8 € |
| Coste por cliente de pago | < 25 € (≈ 2,5 meses de Pro) | > 50 € |

## 5. Cómo conseguir los primeros 100 clientes

En orden. Los primeros 10–20 clientes no llegan por anuncios, sino por contacto directo.

1. **Lista de espera (semanas −4 a 0).** Página en chartiko.com con "Acceso anticipado + precio de fundador". Todo el contenido orgánico apunta ahí. Objetivo: 300–500 emails.
2. **Red personal y 20 conversaciones.** Hablar con 20 traders minoristas (conocidos, X, Rankia, Telegram): videollamada de 20 minutos viendo cómo usan Chartiko. Sirve para encontrar fallos de onboarding y, a menudo, sale el primer cliente.
3. ***Build in public* en X.** Contar el proceso: métricas, patrones nuevos, errores. Los fundadores que lo hacen consiguen sus primeros usuarios entre sus seguidores.
4. **Precio de fundador.** 6,99 €/mes o 69 €/año **de por vida** para los 100 primeros. Crea urgencia sin descuentos permanentes.
5. **Prueba Pro de 14 días sin tarjeta** para todo registro nuevo, con fecha de fin visible.
6. **Rankia y Telegram.** Aportar valor primero (explicar patrones, responder dudas) y mencionar Chartiko solo cuando venga a cuento.
7. **Programa de afiliados.** 30 % recurrente durante 12 meses para creadores de contenido y academias de trading. Coste cero hasta que traen ventas.
8. **Referidos.** "Invita a un amigo: un mes de Pro gratis para los dos".
9. **Contenido "Radar semanal"** en email, X y Telegram: los patrones más interesantes de la semana, explicados de forma educativa, con enlace al gráfico en Chartiko. Es el motor de contenido que el propio producto genera solo.

## 6. Retención

### Activación

Un usuario está **activado** cuando, en sus primeras 48 h, **crea una Watchlist con 3 o más valores y activa al menos una alerta**. Quien recibe su primera alerta útil tiene muchas más probabilidades de pagar y quedarse. Todo el onboarding empuja hacia ese momento.

### Secuencia de onboarding por email

| Día | Email | Objetivo |
|---|---|---|
| D0 | Bienvenida: 3 pasos (crea tu Watchlist, activa alertas, explora un patrón) | Activación |
| D1 | "Qué es un doble suelo y cómo lo detecta Chartiko" | Educación y confianza en las reglas |
| D3 | Si no está activado: "Te falta un paso: activa tu primera alerta" | Recuperar a quien se quedó a medias |
| D7 | Resumen personal: "Esta semana se detectaron N patrones en tu Watchlist" | Mostrar valor |
| D11 | "Tu prueba Pro acaba en 3 días": qué pierdes y precio de fundador | Conversión |
| D14 | Fin de la prueba: pasa a Gratis, no se bloquea la cuenta | Mantener al usuario en el embudo |

### Hábito y valor continuo

- **Digest diario de alertas** (email, y Telegram como opción) tras el cierre: es la razón principal para volver.
- **Seguimiento de patrones:** "El doble suelo detectado en MSFT hace 20 sesiones: así ha evolucionado". Muestra que el sistema es honesto, sin convertirlo en recomendación.
- **Novedades del mes:** un email mensual con lo nuevo (patrones, mercados, mejoras). Los usuarios ven que el producto avanza.
- **Comunidad:** grupo de Telegram para clientes Pro, con el fundador presente.

### Reducir bajas

- **Plan anual** con dos meses gratis (99 € frente a 119,88 €). Los clientes anuales se dan de baja mucho menos.
- **Flujo de cancelación** con una pregunta ("¿por qué te vas?") y la opción de **pausar** 1–3 meses en lugar de cancelar.
- **Encuesta NPS el día 30.** A los detractores se les escribe personalmente.
- **Aviso de pago fallido** con reintentos automáticos (lo gestionan Stripe o Paddle). Evita bajas involuntarias.

## 7. KPIs

| Etapa | Métrica | Objetivo de los 3 primeros meses (orientativo) | Fuente |
|---|---|---|---|
| Atracción | Visitas únicas/mes | 3.000 → 10.000 | Umami/Plausible |
| Captación | Visita → registro | > 5 % | Analítica + BD |
| Activación | Registro → activado (48 h) | > 35 % | BD (Watchlist + alerta) |
| Conversión | Prueba → pago | > 8 % | Pasarela de pago |
| Retención | Baja mensual (*churn*) de Pro | < 8 % | Pasarela de pago |
| Ingresos | Clientes de pago | 100 al final del mes 3 | Pasarela de pago |
| Eficiencia | Coste de captación pagada (CAC) | < 25 € | Gasto en anuncios / clientes atribuidos |

Con unos 100 € de anuncios más los costes de datos, VPS e IA, el punto de equilibrio está aproximadamente en **25–40 clientes Pro**, según el coste final de la licencia de datos (R1).

## 8. Calendario de 12 semanas

| Semana | Acciones |
|---|---|
| −4 a −1 | Cerrar R1–R6. Página de lista de espera. Abrir perfiles (X, Telegram, YouTube, Instagram). 10 piezas de contenido preparadas. Empezar *build in public*. 10 de las 20 conversaciones con traders. |
| 0 (lanzamiento) | Email a la lista de espera con precio de fundador. Hilo de lanzamiento en X. Post en Rankia (según normas). Vídeo de YouTube "Presentando Chartiko". Activar los 100 € de Meta del mes 1. |
| 1–2 | Llamadas de onboarding con los primeros usuarios. Corregir fricciones. Radar semanal nº 1 y 2. |
| 3–4 | Revisar las métricas de corte de los anuncios. Lanzar el programa de afiliados. Primer contacto con 10 microinfluencers. |
| 5–8 | Primera mención patrocinada. SEO programático: páginas por patrón. Encuesta NPS a los primeros clientes. |
| 9–12 | Concentrar el presupuesto en el canal ganador. Páginas ticker × patrón. Decidir el siguiente hito de producto (IBEX/Mercado Continuo) según lo que pidan los clientes. |

## 9. Herramientas (coste 0 € o casi)

| Necesidad | Herramienta |
|---|---|
| Email | Brevo (ya previsto en `alerts-email-golive`) |
| Analítica | Umami autoalojado en el VPS |
| Diseño y vídeo | Canva y CapCut, planes gratuitos |
| Programación de redes | Buffer, plan gratuito |
| Pagos | Paddle o Lemon Squeezy (comisión por venta, sin cuota fija) |
| Búsqueda | Google Search Console |

## 10. Riesgos

- **Regulatorio:** contenido interpretado como recomendación de inversión (ver R6). Mitigación: tono educativo, avisos y nada de rentabilidades.
- **Datos:** usar capturas o publicar datos sin licencia (ver R1).
- **Tiempo del fundador:** el contenido diario quema. Mitigación: automatizar el Radar y el canal de Telegram desde el propio producto y agrupar la grabación de vídeos en una sesión semanal.
- **Plataformas publicitarias:** Meta puede restringir anuncios de servicios financieros. Mitigación: presentar Chartiko como herramienta educativa o de software, y tener el canal orgánico como base.
