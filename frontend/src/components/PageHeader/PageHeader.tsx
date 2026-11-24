import { useEffect, useId, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { resolvePage } from '../../routes/pageHierarchy'

export interface PageHeaderProps {
  /** 画面名。省略時は現在のルートの pageHierarchy の title。 */
  title?: string
  /** 画面の目的の説明(必須)。です・ます調で句点で終える。 */
  description: ReactNode
  /** 画面全体の操作(新規作成・CSV・関連画面への移動など)。右寄せ。 */
  actions?: ReactNode
  /** 状態のバッジ・期間など。見出しの横。 */
  meta?: ReactNode
}

/** 管理画面(/admin 配下)の本文先頭に置く見出し+説明。管理画面以外では使わない。 */
export function PageHeader({ title, description, actions, meta }: PageHeaderProps) {
  const headingId = useId()
  const { pathname } = useLocation()
  const resolvedTitle = title ?? resolvePage(pathname)?.title ?? null

  useEffect(() => {
    if (resolvedTitle === null && import.meta.env.DEV) {
      console.warn(`PageHeader: ${pathname} の title を pageHierarchy から解決できません。title を指定してください。`)
    }
  }, [resolvedTitle, pathname])

  return (
    <section aria-labelledby={headingId} className="@container">
      <div className="flex flex-col gap-3 @2xl:flex-row @2xl:items-start @2xl:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            {resolvedTitle !== null && (
              <h1 id={headingId} className="text-lg font-semibold text-foreground">
                {resolvedTitle}
              </h1>
            )}
            {meta}
          </div>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2 @2xl:shrink-0">{actions}</div>}
      </div>
    </section>
  )
}
