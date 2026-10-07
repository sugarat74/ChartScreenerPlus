import type { Messages } from './messages/es.ts'

/** Dot-separated path to every string leaf of the catalog shape. */
type Leaves<T, Prefix extends string = ''> = {
  [K in keyof T & string]: T[K] extends string ? `${Prefix}${K}` : Leaves<T[K], `${Prefix}${K}.`>
}[keyof T & string]

export type MessageKey = Leaves<Messages>

export type MessageParams = Record<string, string | number>

export type Translate = (key: MessageKey, params?: MessageParams) => string

function lookup(messages: Messages, key: string): string | undefined {
  let node: unknown = messages
  for (const part of key.split('.')) {
    if (node === null || typeof node !== 'object') {
      return undefined
    }
    node = (node as Record<string, unknown>)[part]
  }
  return typeof node === 'string' ? node : undefined
}

/**
 * Resolve `key` in `messages`, falling back to `fallback` and finally to the
 * key itself so a missing string is visible instead of blank. `{name}`
 * placeholders are replaced from `params`; unknown placeholders stay as-is.
 */
export function translate(
  messages: Messages,
  key: MessageKey,
  params?: MessageParams,
  fallback?: Messages,
): string {
  const template = lookup(messages, key) ?? (fallback ? lookup(fallback, key) : undefined) ?? key
  if (!params) {
    return template
  }
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match,
  )
}
