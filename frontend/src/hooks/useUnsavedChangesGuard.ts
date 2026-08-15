import { useEffect } from 'react'

/**
 * 編集中に変更が失われるおそれがある間、タブを閉じる・リロードする操作に対してブラウザ標準の
 * 確認を出す(`ui-interaction-patterns` §2.9)。react-router-domはBrowserRouter(データ
 * ルーターではない)構成のため`useBlocker`が使えず、アプリ内Link遷移を個別にブロックする
 * 仕組みは大掛かりなルーティング変更が必要になるため、現時点ではタブクローズ・リロードのみを
 * 対象にする。
 */
export function useUnsavedChangesGuard(active: boolean) {
  useEffect(() => {
    if (!active) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [active])
}
