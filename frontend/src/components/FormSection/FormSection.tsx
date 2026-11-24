import { useId, type ReactNode } from 'react'

export interface FormSectionProps {
  title: string
  description?: ReactNode
  /** 見出しのレベル。Card の title を外した画面(h1 の直後)では 2、Card の title の下では 3(既定)。 */
  headingLevel?: 2 | 3
  children: ReactNode
}

/** フォームの項目をまとまりで区切る。見出しの下に区切り線を引く。枠線では囲まない。 */
export function FormSection({ title, description, headingLevel = 3, children }: FormSectionProps) {
  const headingId = useId()
  const Heading = headingLevel === 2 ? 'h2' : 'h3'

  return (
    <section aria-labelledby={headingId} className="mt-8 first:mt-0">
      <Heading id={headingId} className="border-b pb-2 text-sm font-semibold text-foreground">
        {title}
      </Heading>
      {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
      <div className="mt-4">{children}</div>
    </section>
  )
}
