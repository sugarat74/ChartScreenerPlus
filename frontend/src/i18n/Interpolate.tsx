import { Fragment } from 'react'
import type { ReactNode } from 'react'

/**
 * Render a translated template whose `{name}` placeholders become React nodes,
 * so emphasis (e.g. bold counts) survives word-order differences between
 * languages. Pass the raw template: `t(key)` without params keeps `{name}`.
 */
export default function Interpolate({
  template,
  values,
}: {
  template: string
  values: Record<string, ReactNode>
}) {
  const parts = template.split(/(\{\w+\})/g)
  return (
    <>
      {parts.map((part, index) => {
        const match = /^\{(\w+)\}$/.exec(part)
        const node = match && Object.prototype.hasOwnProperty.call(values, match[1]) ? values[match[1]] : part
        return <Fragment key={index}>{node}</Fragment>
      })}
    </>
  )
}
