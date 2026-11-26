export interface OnboardingFormValues {
  ms365TenantId: string
  ms365ClientId: string
  ms365ClientSecret: string
  ms365MockEnabled: boolean
  adminName: string
  adminEmail: string
  adminPassword: string
  adminPasswordConfirmation: string
}

export const INITIAL_ONBOARDING_FORM: OnboardingFormValues = {
  ms365TenantId: '',
  ms365ClientId: '',
  ms365ClientSecret: '',
  ms365MockEnabled: false,
  adminName: '',
  adminEmail: '',
  adminPassword: '',
  adminPasswordConfirmation: '',
}

/** 初回設定フォームに、初期値から変更があるか(モードの切り替えは含めない)。 */
export function isOnboardingDirty(base: OnboardingFormValues, current: OnboardingFormValues): boolean {
  return (Object.keys(base) as (keyof OnboardingFormValues)[]).some((key) => base[key] !== current[key])
}
