import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { completeOnboardingLocal, fetchOnboardingStatus, startOnboardingSso } from '../api/onboarding'
import type { OnboardingLocalInput, OnboardingSsoInput } from '../api/types'

const ONBOARDING_STATUS_KEY = ['onboarding-status']

export function useOnboardingStatus() {
  return useQuery({ queryKey: ONBOARDING_STATUS_KEY, queryFn: fetchOnboardingStatus })
}

/** SSOモードを開始する(実際のログインへのリダイレクトURLを取得するだけで、まだトークンは発行されない)。 */
export function useStartOnboardingSso() {
  return useMutation({
    mutationFn: (input: OnboardingSsoInput) => startOnboardingSso(input),
  })
}

export function useCompleteOnboardingLocal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: OnboardingLocalInput) => completeOnboardingLocal(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ONBOARDING_STATUS_KEY })
    },
  })
}
