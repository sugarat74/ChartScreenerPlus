import { useSearchParams } from 'react-router'
import { nextPath, NEXT_PARAM } from './nextPath.ts'

/** The validated `?next=` return path of the current auth page (home when absent or unsafe). */
export function useNextPath(): string {
  const [params] = useSearchParams()

  return nextPath(params.get(NEXT_PARAM))
}
