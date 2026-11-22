import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchAssetNumberRuleCategories } from './assetNumberRules'
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
