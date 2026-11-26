import { Button } from '../Button/Button'

/**
 * ルーターの根(`main.tsx`の`errorElement`)で描画の例外を受けたときの画面。react-routerの
 * 既定の画面(開発者向けの英語)を出さず、再読み込みだけを案内する。
 */
export function RootErrorFallback() {
  return (
    <div role="alert" className="flex min-h-screen flex-col items-center justify-center gap-4 p-6">
      <p>画面の表示中にエラーが発生しました。</p>
      <Button onClick={() => window.location.reload()}>再読み込み</Button>
    </div>
  )
}
