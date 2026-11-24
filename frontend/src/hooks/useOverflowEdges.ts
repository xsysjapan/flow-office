import { useEffect, useRef } from 'react'

export interface OverflowEdges {
  /** 左(開始側)に隠れている内容がある。 */
  start: boolean
  /** 右(終了側)に隠れている内容がある。 */
  end: boolean
}

/** 端の判定の許容誤差(px)。小数のスクロール位置で端に着いても残る隙間を無視する。 */
const EDGE_TOLERANCE = 1

/** 横スクロールの寸法から、どちらの端に隠れた内容があるかを求める純関数。 */
export function computeOverflowEdges(metrics: {
  scrollWidth: number
  clientWidth: number
  scrollLeft: number
}): OverflowEdges {
  const { scrollWidth, clientWidth, scrollLeft } = metrics
  if (scrollWidth - clientWidth <= EDGE_TOLERANCE) return { start: false, end: false }
  return {
    start: scrollLeft > EDGE_TOLERANCE,
    end: scrollLeft + clientWidth < scrollWidth - EDGE_TOLERANCE,
  }
}

/**
 * 横スクロールできる要素が、はみ出している側の端に`data-overflow-start`・`data-overflow-end`を付ける
 * (スクロールとサイズの変化を見る)。はみ出していなければどちらも付けない。
 * 影などの見た目は、呼び出し側が`data-[overflow-start]:`・`data-[overflow-end]:`で付ける。
 */
export function useOverflowEdges<T extends HTMLElement>() {
  const ref = useRef<T>(null)

  useEffect(() => {
    const element = ref.current
    if (!element) return

    const update = () => {
      const edges = computeOverflowEdges(element)
      element.toggleAttribute('data-overflow-start', edges.start)
      element.toggleAttribute('data-overflow-end', edges.end)
    }

    update()
    element.addEventListener('scroll', update, { passive: true })
    const observer = new ResizeObserver(update)
    observer.observe(element)
    // 列の増減などで内容の幅だけが変わる場合も拾う。
    Array.from(element.children).forEach((child) => observer.observe(child))

    return () => {
      element.removeEventListener('scroll', update)
      observer.disconnect()
    }
  }, [])

  return ref
}
