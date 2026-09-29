interface AuthFieldProps {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  type?: string
  autoComplete?: string
  error?: string
}

/**
 * Token-styled form field (DESIGN.md): 2px ink border, hard shadow, mono
 * label and loss-colored error text.
 */
export default function AuthField({
  id,
  label,
  value,
  onChange,
  type = 'text',
  autoComplete,
  error,
}: AuthFieldProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={id}
        className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant"
      >
        {label}
      </label>
      <input
        id={id}
        name={id}
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        autoComplete={autoComplete}
        required
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        className="rounded-md border-2 border-outline bg-surface-bright px-3 py-2 text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-shadow focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
      />
      {error ? (
        <p id={`${id}-error`} className="font-mono text-[11px] font-bold text-secondary">
          {error}
        </p>
      ) : null}
    </div>
  )
}
