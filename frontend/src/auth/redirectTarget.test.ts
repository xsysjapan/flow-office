import { describe, expect, it } from 'vitest'
import { getSafeRedirectTarget } from './redirectTarget'

describe('getSafeRedirectTarget', () => {
  it('相対パスをそのまま返す', () => {
    expect(getSafeRedirectTarget('/attendance/today')).toBe('/attendance/today')
  })

  it('クエリ・ハッシュ付きの相対パスを返す', () => {
    expect(getSafeRedirectTarget('/attendance/monthly?year=2026&month=9#top')).toBe(
      '/attendance/monthly?year=2026&month=9#top',
    )
  })

  it('null・undefined・空文字はnullを返す', () => {
    expect(getSafeRedirectTarget(null)).toBeNull()
    expect(getSafeRedirectTarget(undefined)).toBeNull()
    expect(getSafeRedirectTarget('')).toBeNull()
  })

  it('絶対URL(外部サイト)はnullを返す', () => {
    expect(getSafeRedirectTarget('https://evil.example/phishing')).toBeNull()
    expect(getSafeRedirectTarget('http://evil.example')).toBeNull()
  })

  it('プロトコル相対URLはnullを返す', () => {
    expect(getSafeRedirectTarget('//evil.example')).toBeNull()
    expect(getSafeRedirectTarget('/\\evil.example')).toBeNull()
  })

  it('javascriptスキーム等はnullを返す', () => {
    expect(getSafeRedirectTarget('javascript:alert(1)')).toBeNull()
  })

  it('/loginや/auth/callbackへの戻りはnullを返す(ループ防止)', () => {
    expect(getSafeRedirectTarget('/login')).toBeNull()
    expect(getSafeRedirectTarget('/login?redirect=/')).toBeNull()
    expect(getSafeRedirectTarget('/auth/callback')).toBeNull()
    expect(getSafeRedirectTarget('/auth/callback/foo')).toBeNull()
  })

  it('先頭が/でない相対パスはnullを返す', () => {
    expect(getSafeRedirectTarget('attendance/today')).toBeNull()
  })
})
