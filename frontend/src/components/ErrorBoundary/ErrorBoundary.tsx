import { Component, type ErrorInfo, type ReactNode } from 'react'
import { CircleAlert } from 'lucide-react'
import { Alert, AlertDescription } from '../ui/alert'
import { Button } from '../Button/Button'

export interface ErrorBoundaryProps {
  children: ReactNode
}

interface ErrorBoundaryState {
  hasError: boolean
}

/**
 * 描画中(レンダー時)の予期しない例外を捕捉し、アプリ全体が白画面になることを防ぐ
 * 最終防衛ライン。App.tsxのルート直下に1つだけ配置する(個別ページを都度囲むものではない)。
 * データ取得エラーは各画面のErrorMessageコンポーネントで扱うため、ここはあくまで
 * 想定外の例外(バグ)向けのフォールバック。
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false }

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Unhandled render error', error, errorInfo)
  }

  handleReload = () => {
    window.location.reload()
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="flex min-h-screen items-center justify-center p-6">
          <div className="w-full max-w-md">
            <Alert variant="destructive" className="mb-4">
              <CircleAlert />
              <AlertDescription>
                <p>予期しないエラーが発生しました。</p>
              </AlertDescription>
            </Alert>
            <Button onClick={this.handleReload}>再読み込み</Button>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
