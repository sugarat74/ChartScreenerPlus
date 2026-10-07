import type { Translate } from '../../../i18n/translate.ts'
import type { AdminDevice } from '../../../lib/api.ts'

/** "Chrome · Windows"; unknown parts use the translated "unknown" label. */
export function deviceLabel(device: AdminDevice, t: Translate): string {
  const unknown = t('adminUsers.unknown')
  return `${device.browser ?? unknown} · ${device.os ?? unknown}`
}

/** Read a positive integer page from a URL param (1 when absent/invalid). */
export function pageParam(value: string | null): number {
  const page = Number(value)
  return Number.isInteger(page) && page > 0 ? page : 1
}
