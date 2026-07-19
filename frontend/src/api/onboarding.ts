import { apiFetch } from './client'
import type { OnboardingInput, OnboardingResult, OnboardingStatus } from './types'

export function fetchOnboardingStatus(): Promise<OnboardingStatus> {
  return apiFetch('/onboarding/status')
}

export function submitOnboarding(input: OnboardingInput): Promise<OnboardingResult> {
  return apiFetch('/onboarding', { method: 'POST', body: input })
}
