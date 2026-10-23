import { Link } from 'react-router'
import { LOGIN_ROUTE } from '../../nav.ts'

/**
 * Dashed sign-in box shown to a Visitor in place of a Market Context surface
 * (the /mercado page, the chart strip and the Screener sector filters) once the
 * owner's go-live flag is on. A plain link: never a redirect, modal or fetch.
 */
export default function MarketSignInBox({ title, body, link }: { title: string; body: string; link: string }) {
  return (
    <div className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-md border-2 border-dashed border-outline-variant bg-surface-container px-3 py-2">
      <div className="min-w-0">
        <p className="font-headline text-[11px] font-bold uppercase tracking-wide text-on-surface">{title}</p>
        <p className="text-xs text-on-surface-variant">{body}</p>
      </div>
      <Link
        to={LOGIN_ROUTE}
        className="font-mono text-xs font-bold text-on-surface underline underline-offset-4 focus:outline-2 focus:outline-offset-2 focus:outline-outline"
      >
        {link}
      </Link>
    </div>
  )
}
