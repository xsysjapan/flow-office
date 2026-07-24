import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchMyIntegrations,
  registerIntegration,
  reissueIntegrationToken,
  revokeIntegration,
  type RegisterIntegrationInput,
} from '../api/integrations'

export function useMyIntegrations() {
  return useQuery({
    queryKey: ['integrations', 'me'],
    queryFn: fetchMyIntegrations,
  })
}

export function useRegisterIntegration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RegisterIntegrationInput) => registerIntegration(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['integrations', 'me'] })
    },
  })
}

export function useReissueIntegrationToken() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => reissueIntegrationToken(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['integrations', 'me'] })
    },
  })
}

export function useRevokeIntegration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => revokeIntegration(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['integrations', 'me'] })
    },
  })
}
