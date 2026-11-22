import type { CSSProperties } from 'react'
import { Toaster as SonnerToaster, type ToasterProps } from 'sonner'

/**
 * 成功通知のトースト置き場。Appのルートに1つだけ置く。表示は`src/lib/notify.ts`経由で行い、
 * 画面から`sonner`を直接importしない。next-themesは使わず、色はデザイントークン(CSS変数)に合わせる。
 * PC(sm以上)は右下、モバイル(sonnerの600px以下)は下端の全幅になる。
 */
export function Toaster(props: ToasterProps) {
  return (
    <SonnerToaster
      theme="system"
      position="bottom-right"
      duration={10000}
      visibleToasts={3}
      mobileOffset={16}
      containerAriaLabel="通知"
      style={
        {
          '--normal-bg': 'var(--popover)',
          '--normal-text': 'var(--popover-foreground)',
          '--normal-border': 'var(--border)',
          '--success-bg': 'var(--popover)',
          '--success-text': 'var(--popover-foreground)',
          '--success-border': 'var(--success)',
          '--border-radius': 'var(--radius)',
        } as CSSProperties
      }
      {...props}
    />
  )
}
