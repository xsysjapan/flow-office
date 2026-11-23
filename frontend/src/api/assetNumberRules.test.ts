import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchAssetNumberRuleCategories, fetchAssetNumberRules, updateAssetNumberRule } from './assetNumberRules'
import { clearToken } from './client'

describe('fetchAssetNumberRuleCategories', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    clearToken()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns the category list without the { data } envelope', async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ data: ['A'] }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
    )

    await expect(fetchAssetNumberRuleCategories()).resolves.toEqual(['A'])
  })
})

describe('asset number rules API shape', () => {
  const rule = { id: 'r1', category: 'ノートPC', prefix: 'NPC', digit_count: 5, next_number: 3, is_active: false, is_default: false }

  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    clearToken()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function json(body: unknown) {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'content-type': 'application/json' } })
  }

  it('returns the unwrapped snake_case array sent by the backend', async () => {
    vi.mocked(fetch).mockResolvedValue(json([rule]))

    const rules = await fetchAssetNumberRules()
    expect(rules[0].digit_count).toBe(5)
    expect(rules[0].next_number).toBe(3)
    expect(rules[0].is_active).toBe(false)
  })

  it('sends { prefix, digit_count, is_active } and returns the snake_case rule', async () => {
    vi.mocked(fetch).mockResolvedValue(json(rule))

    const saved = await updateAssetNumberRule('ノートPC', { prefix: 'NPC', digit_count: 5, is_active: false })
    expect(saved.is_active).toBe(false)
    const init = vi.mocked(fetch).mock.calls[0][1] as RequestInit
    expect(JSON.parse(init.body as string)).toEqual({ prefix: 'NPC', digit_count: 5, is_active: false })
  })
})
