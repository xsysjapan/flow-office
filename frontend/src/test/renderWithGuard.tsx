import { render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { UnsavedChangesProvider } from '../hooks/useUnsavedChangesGuard'

/**
 * 離脱警告(`UnsavedChangesProvider`)つきで、データルーター(`createMemoryRouter`)の下に描画する
 * テスト用の補助。`main.tsx`と同じく`path: '*'`の1ルートで`ui`を包むので、`ui`の中で`<Routes>`を使える。
 */
export function renderWithGuard(ui: ReactNode, { initialEntries = ['/'] }: { initialEntries?: string[] } = {}) {
  const router = createMemoryRouter(
    [{ path: '*', element: <UnsavedChangesProvider>{ui}</UnsavedChangesProvider> }],
    { initialEntries },
  )
  return { router, ...render(<RouterProvider router={router} />) }
}
