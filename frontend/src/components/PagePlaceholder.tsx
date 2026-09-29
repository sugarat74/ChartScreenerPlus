interface PagePlaceholderProps {
  eyebrow: string
  title: string
  description: string
  featureId: string
}

/**
 * Token-styled stub for a shell surface whose real content lands in a later
 * feature. It intentionally renders no data and makes no API calls.
 */
export default function PagePlaceholder({
  eyebrow,
  title,
  description,
  featureId,
}: PagePlaceholderProps) {
  return (
    <section className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-3">
        <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {eyebrow}
        </span>
        <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
          pendiente: {featureId}
        </span>
      </div>

      <div>
        <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {title}
        </h2>
        <p className="mt-2 max-w-2xl text-sm text-on-surface-variant">{description}</p>
      </div>

      <div className="rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_#1a1a1a]">
        <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
          Placeholder
        </p>
        <p className="mt-2 text-sm text-on-surface">
          Esta superficie queda definida por el app shell. El contenido real llega con la
          feature <span className="font-mono font-bold">{featureId}</span>.
        </p>
      </div>
    </section>
  )
}
