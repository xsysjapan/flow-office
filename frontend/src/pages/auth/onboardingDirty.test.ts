import { describe, expect, it } from 'vitest'
import { INITIAL_ONBOARDING_FORM, isOnboardingDirty } from './onboardingDirty'

describe('isOnboardingDirty', () => {
  const base = INITIAL_ONBOARDING_FORM
  it('変更なしはfalse', () => {
    expect(isOnboardingDirty(base, { ...base })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isOnboardingDirty(base, { ...base, ms365TenantId: 'tenant' })).toBe(true)
    expect(isOnboardingDirty(base, { ...base, adminEmail: 'a@example.com' })).toBe(true)
    expect(isOnboardingDirty(base, { ...base, ms365MockEnabled: true })).toBe(true)
  })
  it('元に戻すとfalse', () => {
    expect(isOnboardingDirty(base, { ...base, adminName: '' })).toBe(false)
  })
})
