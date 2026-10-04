import { Link } from 'react-router'

const CTA_CLASS =
  'inline-flex w-fit items-center rounded-md border-2 border-outline bg-primary-container px-5 py-3 font-headline text-sm font-bold uppercase tracking-wide text-on-primary-container shadow-[3px_3px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:outline-2 focus:outline-offset-4 focus:outline-outline'

export default function LandingPage() {
  return (
    <article className="mx-auto flex w-full max-w-5xl flex-col gap-12 py-6 sm:py-12">
      <header className="flex flex-col items-start gap-6 border-b-2 border-outline pb-10">
        <p className="font-mono text-xs font-bold uppercase tracking-[0.18em] text-on-surface-variant">
          Análisis técnico de acciones
        </p>
        <h1 className="max-w-4xl font-headline text-4xl font-black leading-tight tracking-tight sm:text-6xl">
          Encuentra Candidates entre cientos de acciones.
        </h1>
        <p className="max-w-3xl text-base leading-7 text-on-surface-variant sm:text-lg">
          Chartiko aplica filtros técnicos al universo del S&amp;P 500, ordena los resultados
          y te permite revisar cada Candidate en un gráfico interactivo.
        </p>
        <Link to="/screener" className={CTA_CLASS}>
          Abrir el Screener <span aria-hidden="true" className="ml-2">→</span>
        </Link>
      </header>

      <section aria-labelledby="workflow-heading" className="grid gap-6 md:grid-cols-3">
        <h2 id="workflow-heading" className="sr-only">Cómo funciona Chartiko</h2>
        <article className="border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs font-bold text-on-surface-variant">01 / FILTRA</p>
          <h3 className="mt-3 font-headline text-xl font-bold">Define criterios técnicos</h3>
          <p className="mt-2 text-sm leading-6 text-on-surface-variant">
            Combina señales e indicadores como medias móviles, RSI, MACD y volumen para acotar
            la lista de acciones.
          </p>
        </article>
        <article className="border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs font-bold text-on-surface-variant">02 / ORDENA</p>
          <h3 className="mt-3 font-headline text-xl font-bold">Revisa los Candidates</h3>
          <p className="mt-2 text-sm leading-6 text-on-surface-variant">
            Consulta qué instrumentos cumplen los filtros y cambia el criterio de ordenación
            para priorizar la revisión.
          </p>
        </article>
        <article className="border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs font-bold text-on-surface-variant">03 / EXPLORA</p>
          <h3 className="mt-3 font-headline text-xl font-bold">Inspecciona el gráfico</h3>
          <p className="mt-2 text-sm leading-6 text-on-surface-variant">
            Abre un instrumento para ver su historial de precios, volumen, medias móviles e
            información de Signals.
          </p>
        </article>
      </section>

      <section aria-labelledby="method-heading" className="max-w-3xl">
        <h2 id="method-heading" className="font-headline text-2xl font-black tracking-tight sm:text-3xl">
          Señales basadas en reglas claras
        </h2>
        <p className="mt-3 text-sm leading-7 text-on-surface-variant sm:text-base">
          Chartiko calcula resultados a partir de datos diarios y reglas técnicas deterministas.
          Una Signal describe una condición detectada, por ejemplo un cruce de medias, RSI o un
          breakout de Pivot; no es una recomendación ni una predicción de rentabilidad. Los datos
          y los indicadores disponibles dependen del historial registrado para cada instrumento.
        </p>
      </section>

      <section aria-labelledby="faq-heading" className="max-w-3xl border-t-2 border-outline pt-8">
        <h2 id="faq-heading" className="font-headline text-2xl font-black tracking-tight">
          Preguntas frecuentes
        </h2>
        <div className="mt-5 divide-y divide-outline-variant">
          <div className="py-4">
            <h3 className="font-headline text-base font-bold">¿Qué mercado cubre?</h3>
            <p className="mt-2 text-sm leading-6 text-on-surface-variant">
              El Screener parte del universo de acciones del S&amp;P 500.
            </p>
          </div>
          <div className="py-4">
            <h3 className="font-headline text-base font-bold">¿Qué significa una Signal?</h3>
            <p className="mt-2 text-sm leading-6 text-on-surface-variant">
              Es una condición técnica calculada mediante reglas reproducibles sobre indicadores;
              por sí sola no determina una decisión de inversión.
            </p>
          </div>
          <div className="py-4">
            <h3 className="font-headline text-base font-bold">¿Chartiko ofrece datos en tiempo real?</h3>
            <p className="mt-2 text-sm leading-6 text-on-surface-variant">
              No. Chartiko analiza datos diarios y no ofrece cotizaciones intradía.
            </p>
          </div>
        </div>
        <Link to="/screener" className={`${CTA_CLASS} mt-5`}>
          Explorar el Screener
        </Link>
      </section>

      <p className="border-t border-outline-variant pt-5 text-xs leading-5 text-on-surface-variant">
        Chartiko es una herramienta de análisis técnico. La información presentada tiene fines
        informativos y no constituye asesoramiento financiero.
      </p>
    </article>
  )
}
