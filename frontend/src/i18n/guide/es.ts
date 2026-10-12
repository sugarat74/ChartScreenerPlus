import type { GuideTexts } from './types.ts'

/**
 * Manual de uso en español (fuente). Cada umbral y etiqueta debe coincidir con
 * el código y con los textos de la interfaz; ver `types.ts`.
 */
export const guideEs: GuideTexts = {
  eyebrow: 'Manual de uso',
  title: 'Cómo funciona Chartiko',
  intro:
    'Chartiko es un screener técnico de acciones del S&P 500: filtra los valores con criterios de análisis técnico, los ordena y te deja revisar cada uno en un gráfico. Este manual explica cada pantalla, cada filtro y cada indicador, señal y patrón que verás.',
  openScreener: 'Abrir el Screener',
  tocTitle: 'Contenido',
  sections: [
    {
      id: 'empezar',
      title: 'Empezar en tres pasos',
      steps: [
        {
          title: 'Filtra',
          body: 'En el Screener (la página principal) combina señales técnicas, rangos de RSI, volumen relativo, medias móviles y patrones chartistas para quedarte solo con los valores que te interesan.',
        },
        {
          title: 'Ordena y recorre la lista',
          body: 'Elige el criterio de «Ordenar por» y navega por las páginas de candidatos con «Anterior» y «Siguiente». Puedes ver 25, 50, 100 o 200 por página.',
        },
        {
          title: 'Revisa el gráfico',
          body: 'Pulsa el símbolo de un candidato para abrir su gráfico con velas, volumen, medias, señales activas y patrones. Con una cuenta puedes guardar tus filtros y seguir valores.',
        },
      ],
    },
    {
      id: 'datos',
      title: 'Qué datos usa Chartiko',
      paragraphs: [
        'El universo es el S&P 500 (unas 500 acciones de EE. UU.). Chartiko trabaja con datos diarios: una vela por valor y sesión, con apertura, máximo, mínimo, cierre y volumen.',
        'Los datos se actualizan una vez al día, cada día hábil del mercado estadounidense, a partir de las 16:30 (hora de Nueva York), unos 30 minutos después del cierre de la sesión. Después se recalculan los indicadores, las señales, los patrones y el listado. No hay cotizaciones en tiempo real ni intradía.',
        'La fecha de la última sesión aparece en la ficha de cada valor. Si un valor tiene poco historial (por ejemplo, menos de 200 sesiones para la SMA 200), ese indicador queda vacío en lugar de mostrar un valor inventado, y los filtros que lo necesitan no lo incluyen.',
      ],
    },
    {
      id: 'listado',
      title: 'El listado de candidatos',
      paragraphs: [
        'Cada fila es un candidato: un valor que cumple todos los filtros activos. Sin filtros, ves todo el universo ordenado. Encima de la tabla se indica «Mostrando X–Y de Z candidatos».',
      ],
      entries: [
        { term: 'Símbolo / Empresa', summary: 'Ticker, nombre de la empresa y bolsa (por ejemplo, NYSE o NASDAQ). Pulsa el símbolo para abrir su gráfico.' },
        { term: 'Cierre', summary: 'Precio de cierre de la última sesión disponible.' },
        { term: 'Var %', summary: 'Variación porcentual entre el último cierre y el cierre de la sesión anterior. Queda vacía si el valor tiene una sola sesión.' },
        { term: 'RVOL', summary: 'Volumen relativo de la última sesión (ver «Indicadores técnicos»). Un 2,0× significa el doble del volumen medio.' },
        { term: 'RSI (14)', summary: 'Índice de fuerza relativa de 14 sesiones, de 0 a 100 (ver «Indicadores técnicos»).' },
        {
          term: 'Señales',
          summary: 'Etiquetas de las señales técnicas activas y, con borde discontinuo, de los patrones chartistas activos con su estado («En formación» o «Confirmado»).',
        },
      ],
      notes: [
        'Ordenar por: «Volumen relativo (RVOL mayor)» (por defecto), «Confianza (más señales)» —número de señales activas, no una probabilidad—, «Variación diaria» mayor o menor y «RSI» mayor o menor. Los valores sin dato van siempre al final y los empates se ordenan por ticker.',
        'Paginación: «Anterior» y «Siguiente» recorren las páginas y «Por página» elige 25, 50, 100 o 200 candidatos. Cambiar filtros, orden o tamaño vuelve a la página 1.',
        'Los filtros, el orden y la página quedan en la dirección de la página: puedes guardarla en favoritos o compartirla y se abrirá igual.',
      ],
    },
    {
      id: 'filtros',
      title: 'Los filtros del Screener',
      paragraphs: [
        'Todos los filtros activos se combinan entre sí: un candidato debe cumplirlos todos. Dentro de «Señales técnicas» y de «Patrones chartistas», en cambio, basta con cumplir uno de los elegidos. «Limpiar filtros» quita todos los criterios y conserva el orden elegido.',
      ],
      entries: [
        {
          term: 'Señales técnicas',
          summary: 'Muestra solo valores con al menos una de las señales seleccionadas activa en la última sesión.',
          details: ['Las nueve señales se explican en «Señales técnicas».'],
        },
        {
          term: 'RSI (14): Mín y Máx',
          summary: 'Acota el RSI de la última sesión entre los valores que escribas (0 a 100, ambos incluidos). Deja una casilla vacía para no limitar ese extremo.',
          details: ['Ejemplo: Mín 30 y Máx 70 excluye zonas de sobreventa y sobrecompra.'],
        },
        {
          term: 'RVOL mínimo',
          summary: 'Exige un volumen relativo igual o superior a 1×, 1,5×, 2× o 3×. «Todos» desactiva el filtro.',
        },
        {
          term: 'Precio > SMA 200',
          summary: 'Muestra solo valores cuyo último cierre está por encima de su media móvil simple de 200 sesiones, una forma habitual de quedarse con valores en tendencia de fondo alcista.',
        },
        {
          term: 'Cruce de medias',
          summary: '«Alcista» exige que la SMA 50 esté por encima de la SMA 200 y «Bajista», por debajo. «Cualquiera» desactiva el filtro.',
          details: [
            'Describe la situación actual de las medias, no un cruce reciente. Para encontrar el cruce del día usa las señales «Cruce dorado» o «Cruce de la muerte».',
          ],
        },
        {
          term: 'Patrones chartistas',
          summary: 'Muestra valores con un patrón activo de los tipos elegidos (doble suelo, doble techo, taza con asa, bandera alcista).',
          details: [
            'El control de estado («Cualquiera», «En formación», «Confirmado») se activa al elegir algún patrón y filtra solo esos patrones.',
          ],
        },
      ],
    },
    {
      id: 'indicadores',
      title: 'Indicadores técnicos',
      paragraphs: [
        'Chartiko calcula los indicadores a partir de las velas diarias de cada valor. Estos son los que ves en el listado, en el gráfico o detrás de las señales:',
      ],
      entries: [
        {
          term: 'SMA 20, 50 y 200 (media móvil simple)',
          summary: 'Media de los cierres de las últimas 20, 50 o 200 sesiones. Suaviza el precio para ver la tendencia: corta (20), media (50) y larga (200).',
          illustration: { kind: 'movingAverages', alt: 'Precio en negro y tres niveles horizontales de medias móviles simples, azul, rojo y verde, como referencias actuales.' },
          details: [
            'Lectura habitual: precio por encima de la SMA 200 sugiere tendencia de fondo alcista; la SMA 50 por encima de la SMA 200, también.',
            'En el gráfico se muestran como líneas horizontales en su valor actual.',
          ],
        },
        {
          term: 'RSI 14 (índice de fuerza relativa)',
          summary: 'Oscilador de 0 a 100 que compara la fuerza de las subidas y de las bajadas de las últimas 14 sesiones (método de Wilder).',
          illustration: { kind: 'rsi', alt: 'Oscilador RSI azul que cruza una zona superior marcada con línea roja discontinua.' },
          details: [
            '70 o más suele leerse como sobrecompra y 30 o menos como sobreventa; son zonas de atención, no órdenes de compra o venta.',
          ],
        },
        {
          term: 'MACD 12/26/9',
          summary: 'Diferencia entre la media exponencial de 12 sesiones y la de 26 (línea MACD), junto con su media exponencial de 9 sesiones (línea de señal). Mide el impulso de la tendencia.',
          illustration: { kind: 'macd', alt: 'Histograma de MACD a ambos lados de cero y líneas MACD azul y de señal roja que se cruzan.' },
          details: ['Cuando la línea MACD cruza su línea de señal se generan las señales «MACD alcista» o «MACD bajista».'],
        },
        {
          term: 'RVOL (volumen relativo)',
          summary: 'Volumen de la última sesión dividido entre el volumen medio de las 50 sesiones anteriores.',
          illustration: { kind: 'rvol', alt: 'Barras de volumen con una última barra roja muy superior al promedio azul discontinuo.' },
          details: [
            '1× es un volumen normal; 2× o más indica una participación inusual, que suele acompañar a rupturas y noticias.',
          ],
        },
        {
          term: 'Pivote',
          summary: 'Máximo de las 20 sesiones anteriores a la última. Superarlo al cierre es una ruptura.',
          illustration: { kind: 'pivot', alt: 'Precio negro que supera al final una línea horizontal azul que representa el pivote.' },
          details: ['Aparece en el gráfico como línea «PIVOTE» cuando la señal «Ruptura pivote + RVOL» está activa.'],
        },
        {
          term: 'Volumen',
          summary: 'Número de acciones negociadas en cada sesión. Se muestra como barras bajo el gráfico de precio.',
          illustration: { kind: 'volume', alt: 'Línea de precio en la parte superior y barras de volumen verdes y rojas en el panel inferior.' },
        },
      ],
      notes: [
        'Las imágenes son esquemas con datos ficticios: explican la forma de cada indicador, no muestran cotizaciones ni señales actuales.',
        'Chartiko también calcula las EMA de 21 y 55 sesiones, el ADX de 14 y las Bandas de Bollinger (20 sesiones, 2 desviaciones) para futuras funciones; hoy no se muestran ni se usan en los filtros.',
      ],
    },
    {
      id: 'senales',
      title: 'Señales técnicas',
      paragraphs: [
        'Una señal es una condición técnica que se comprueba con reglas fijas sobre la última sesión de cada valor. Se recalculan cada día: si la condición deja de cumplirse, la señal desaparece.',
      ],
      entries: [
        { term: 'Cruce dorado', summary: 'La SMA 50 cruza por encima de la SMA 200 en la última sesión (en la anterior estaba igual o por debajo). Señal clásica de cambio a tendencia alcista.', illustration: { kind: 'goldenCross', alt: 'La SMA 50 azul cruza de abajo arriba la SMA 200 roja en el punto destacado.' } },
        { term: 'Cruce de la muerte', summary: 'La SMA 50 cruza por debajo de la SMA 200 en la última sesión. Señal clásica de cambio a tendencia bajista.', illustration: { kind: 'deathCross', alt: 'La SMA 50 azul cruza de arriba abajo la SMA 200 roja en el punto destacado.' } },
        { term: 'Alineación alcista', summary: 'SMA 20 > SMA 50 > SMA 200: las medias corta, media y larga están ordenadas al alza.', illustration: { kind: 'bullishAlignment', alt: 'Tres medias ordenadas de arriba abajo: SMA 20 verde, SMA 50 azul y SMA 200 roja.' } },
        { term: 'Alineación bajista', summary: 'SMA 20 < SMA 50 < SMA 200: las medias están ordenadas a la baja.', illustration: { kind: 'bearishAlignment', alt: 'Tres medias ordenadas de abajo arriba: SMA 20 verde, SMA 50 azul y SMA 200 roja.' } },
        { term: 'Ruptura pivote + RVOL', summary: 'El cierre supera el máximo de las 20 sesiones anteriores (el pivote) con un volumen relativo de 2× o más. Ruptura con participación.', illustration: { kind: 'pivotBreakoutRvol', alt: 'El último cierre supera el pivote horizontal y la última barra de volumen destaca sobre las anteriores, con RVOL de al menos 2.' } },
        { term: 'RSI sobrecompra', summary: 'RSI 14 igual o superior a 70.', illustration: { kind: 'rsiOverbought', alt: 'La línea RSI azul termina por encima del umbral superior de 70.' } },
        { term: 'RSI sobreventa', summary: 'RSI 14 igual o inferior a 30.', illustration: { kind: 'rsiOversold', alt: 'La línea RSI azul termina por debajo del umbral inferior de 30.' } },
        { term: 'MACD alcista', summary: 'La línea MACD cruza por encima de su línea de señal en la última sesión.', illustration: { kind: 'macdBullish', alt: 'La línea MACD azul cruza de abajo arriba la línea de señal roja en el punto destacado.' } },
        { term: 'MACD bajista', summary: 'La línea MACD cruza por debajo de su línea de señal en la última sesión.', illustration: { kind: 'macdBearish', alt: 'La línea MACD azul cruza de arriba abajo la línea de señal roja en el punto destacado.' } },
      ],
      notes: [
        'Si un indicador no tiene historial suficiente, la señal que depende de él no se activa.',
        'Las imágenes son esquemas con datos ficticios; muestran las condiciones de las señales, no cotizaciones ni señales actuales.',
      ],
    },
    {
      id: 'patrones',
      title: 'Patrones chartistas',
      paragraphs: [
        'Los patrones son formaciones geométricas del precio que Chartiko detecta automáticamente con reglas fijas sobre los máximos y mínimos locales (un máximo o mínimo se confirma cuando lo rodean 5 sesiones a cada lado, así que un patrón aparece como pronto 5 sesiones después de su último máximo o mínimo local).',
        '«En formación» significa que la figura está completa pero aún no ha roto su nivel. «Confirmado» significa que un cierre ha roto el nivel de ruptura (por encima; por debajo en el doble techo) en las últimas 10 sesiones. Pasado ese plazo, o si el precio invalida la figura, el patrón deja de mostrarse. Un patrón en formación también caduca si no rompe a tiempo: 60 sesiones desde el segundo suelo o techo, un asa de más de 25 sesiones o una bandera de más de 20. Chartiko solo muestra el nivel de ruptura: nunca calcula objetivos de precio.',
      ],
      entries: [
        {
          term: 'Doble suelo',
          summary: 'Dos mínimos parecidos (diferencia de hasta un 3 %), separados entre 15 y 120 sesiones, tras una caída de al menos un 15 % en las 60 sesiones previas, con un rebote intermedio de al menos un 10 %.',
          illustration: { kind: 'doubleBottom', alt: 'Precio con dos suelos similares y una subida que supera el nivel horizontal de la cresta intermedia.' },
          details: ['Nivel de ruptura: el máximo entre los dos suelos. Se confirma con un cierre por encima; se invalida con un cierre más de un 3 % por debajo de los suelos.'],
        },
        {
          term: 'Doble techo',
          summary: 'Dos máximos parecidos (hasta un 3 % de diferencia), separados entre 15 y 120 sesiones, tras una subida de al menos un 15 % en las 60 sesiones previas, con una caída intermedia de al menos un 10 %.',
          illustration: { kind: 'doubleTop', alt: 'Precio con dos techos similares y una caída que cruza por debajo del valle intermedio.' },
          details: ['Nivel de ruptura: el mínimo entre los dos techos. Se confirma con un cierre por debajo; se invalida con un cierre más de un 3 % por encima de los techos.'],
        },
        {
          term: 'Taza con asa',
          summary: 'Una taza de 30 a 325 sesiones, con una profundidad de entre el 12 % y el 35 %, el fondo entre el 20 % y el 80 % de su duración, sin picos intermedios por encima del borde más bajo y bordes de altura parecida (hasta un 5 %), seguida de un asa de 5 a 25 sesiones que retrocede como mucho un 12 % y no baja de la mitad de la taza.',
          illustration: { kind: 'cupWithHandle', alt: 'Precio con base redondeada, un asa corta y una subida por encima del máximo del asa.' },
          details: ['Nivel de ruptura: el máximo del asa. Se confirma con un cierre por encima y un volumen relativo de 1,5× o más.'],
        },
        {
          term: 'Bandera alcista',
          summary: 'Un mástil (subida de al menos un 15 % en 15 sesiones o menos) seguido de una bandera de 5 a 20 sesiones que corrige sin subir, con menos volumen y sin retroceder más de la mitad del mástil.',
          illustration: { kind: 'bullFlag', alt: 'Precio que sube con fuerza, desciende dentro de un canal estrecho y rompe al alza.' },
          details: ['Nivel de ruptura: el máximo de la bandera. Se confirma con un cierre por encima.'],
        },
      ],
      notes: [
        'Las imágenes son esquemas con datos ficticios; no son formaciones detectadas en un valor real ni objetivos de precio.',
        'Las reglas usan parámetros iniciales que se irán calibrando. Revisa siempre la figura en el gráfico.',
      ],
    },
    {
      id: 'grafico',
      title: 'El gráfico de cada valor',
      entries: [
        { term: 'Velas', summary: 'Cada vela es una sesión: el cuerpo va de la apertura al cierre (verde si cerró por encima de la apertura, rojo si por debajo) y las mechas marcan el máximo y el mínimo.' },
        { term: 'Volumen', summary: 'Barras en el panel inferior, coloreadas según la vela de cada sesión.' },
        { term: 'Leyenda', summary: 'Encima del gráfico: apertura (O), máximo (H), mínimo (L), cierre (C), volumen, RSI y RVOL de la última sesión, y el valor de las SMA 20, 50 y 200.' },
        { term: 'Líneas de medias', summary: 'Líneas horizontales en el valor actual de las SMA 20, 50 y 200.' },
        { term: 'Línea PIVOTE', summary: 'Aparece cuando la señal «Ruptura pivote + RVOL» está activa, en el nivel del pivote.' },
        { term: 'Patrones', summary: 'Los puntos clave de cada patrón activo se marcan sobre las velas y su nivel de ruptura se dibuja como línea punteada con el nombre del patrón.' },
        { term: 'Paneles «Señales activas» y «Patrones activos»', summary: 'Debajo del gráfico listan en texto todas las señales y patrones con sus fechas y niveles, también para lectores de pantalla.' },
      ],
      notes: [
        'Si faltan medias porque el valor tiene poco historial, verás el aviso de historial insuficiente; el gráfico se muestra igualmente.',
      ],
    },
    {
      id: 'cuenta',
      title: 'Tu cuenta: Screeners guardados y Watchlist',
      paragraphs: [
        'Consultar el Screener y los gráficos no requiere cuenta. Con una cuenta gratuita puedes además:',
      ],
      entries: [
        { term: 'Screeners guardados', summary: 'Guarda con un nombre la combinación actual de filtros y orden, y vuelve a aplicarla con un clic desde el Screener o desde tu Portal.' },
        { term: 'Watchlist', summary: 'Pulsa «Seguir» en el gráfico de un valor para añadirlo a tu lista y verlo en tu Portal; «Siguiendo» indica que ya lo sigues.' },
        { term: 'Portal', summary: 'Reúne tus Screeners guardados y tu Watchlist.' },
      ],
    },
  ],
  faqTitle: 'Preguntas frecuentes',
  faq: [
    {
      question: '¿Qué mercado analiza Chartiko?',
      answer: 'Las acciones del índice S&P 500 de EE. UU., con datos diarios.',
    },
    {
      question: '¿Con qué frecuencia se actualizan los datos?',
      answer: 'Una vez al día, cada día hábil del mercado de EE. UU., a partir de las 16:30 (hora de Nueva York), unos 30 minutos después del cierre. No hay datos en tiempo real ni intradía.',
    },
    {
      question: '¿Qué diferencia hay entre una señal y un patrón?',
      answer: 'Una señal es una condición sobre indicadores en la última sesión (por ejemplo, RSI ≥ 70). Un patrón es una figura del precio que se forma a lo largo de semanas o meses, con un nivel de ruptura.',
    },
    {
      question: '¿«Confianza (más señales)» indica la probabilidad de acierto?',
      answer: 'No. Solo ordena por el número de señales activas de cada valor.',
    },
    {
      question: '¿Chartiko me dice qué comprar?',
      answer: 'No. Chartiko muestra información técnica calculada con reglas fijas; no es asesoramiento ni una recomendación de inversión.',
    },
  ],
  disclaimer:
    'Chartiko ofrece información técnica generada automáticamente con fines informativos y educativos. No constituye asesoramiento de inversión ni una recomendación personalizada. Los datos pueden contener errores o retrasos y las rentabilidades pasadas no garantizan resultados futuros; invertir conlleva riesgo de pérdida.',
}
