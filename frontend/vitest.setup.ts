import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// vitest.config.tsで globals: true にしていないため、@testing-library/react の
// 自動クリーンアップが効かない。テストごとに明示的にDOMを片付ける。
afterEach(() => {
  cleanup()
})

// jsdomにはResizeObserver/PointerEvent周りのAPIが無く、Radix UI(Popover/Select/Dialog等)や
// cmdkがそれらに依存しているためテスト実行時にReferenceErrorになる。最小限のポリフィルを用意する。
if (typeof globalThis.ResizeObserver === 'undefined') {
  class ResizeObserverPolyfill {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  globalThis.ResizeObserver = ResizeObserverPolyfill as unknown as typeof ResizeObserver
}

if (typeof Element.prototype.hasPointerCapture === 'undefined') {
  Element.prototype.hasPointerCapture = () => false
}
if (typeof Element.prototype.setPointerCapture === 'undefined') {
  Element.prototype.setPointerCapture = () => {}
}
if (typeof Element.prototype.releasePointerCapture === 'undefined') {
  Element.prototype.releasePointerCapture = () => {}
}
if (typeof Element.prototype.scrollIntoView === 'undefined') {
  Element.prototype.scrollIntoView = () => {}
}
