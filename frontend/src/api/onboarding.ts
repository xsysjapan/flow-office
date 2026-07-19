import { apiFetch } from './client'
import type {
  OnboardingLocalInput,
  OnboardingResult,
  OnboardingSsoInput,
  OnboardingSsoStartResult,
  OnboardingStatus,
} from './types'

export function fetchOnboardingStatus(): Promise<OnboardingStatus> {
  return apiFetch('/onboarding/status')
}

/** SSOモードを開始する。実際のログインへ遷移するためのURLを返す(トークンはまだ発行しない)。 */
export function startOnboardingSso(input: OnboardingSsoInput): Promise<OnboardingSsoStartResult> {
  return apiFetch('/onboarding/sso', { method: 'POST', body: input })
}

/** ローカルパスワードモードを完了する。その場でトークンが発行される。 */
export function completeOnboardingLocal(input: OnboardingLocalInput): Promise<OnboardingResult> {
  return apiFetch('/onboarding/local', { method: 'POST', body: input })
}
