import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchUser, fetchUsers, updateUserRoles } from '../api/users'

export function useUsers(query?: string) {
  return useQuery({
    queryKey: ['users', query ?? ''],
    queryFn: () => fetchUsers(query),
    placeholderData: keepPreviousData,
  })
}

export function useUser(id: number) {
  return useQuery({
    queryKey: ['users', 'detail', id],
    queryFn: () => fetchUser(id),
    enabled: Number.isFinite(id),
  })
}

export function useUpdateUserRoles() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, roleCodes }: { id: number; roleCodes: string[] }) => updateUserRoles(id, roleCodes),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['users', 'detail', id] })
    },
  })
}
