import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  disableAuthenticationKey,
  fetchAuthenticationKeysForUser,
  issueAuthenticationKey,
  type IssueAuthenticationKeyInput,
} from '../api/authenticationKeys'

export function useAuthenticationKeysForUser(userId: string) {
  return useQuery({
    queryKey: ['authentication-keys', userId],
    queryFn: () => fetchAuthenticationKeysForUser(userId),
    enabled: Boolean(userId),
  })
}

export function useIssueAuthenticationKey() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: IssueAuthenticationKeyInput) => issueAuthenticationKey(input),
    onSuccess: (_, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['authentication-keys', variables.user_id] })
    },
  })
}

export function useDisableAuthenticationKey() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string; userId: string }) => disableAuthenticationKey(id),
    onSuccess: (_, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['authentication-keys', variables.userId] })
    },
  })
}
