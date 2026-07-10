import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// vitest.config.tsで globals: true にしていないため、@testing-library/react の
// 自動クリーンアップが効かない。テストごとに明示的にDOMを片付ける。
afterEach(() => {
  cleanup()
})
