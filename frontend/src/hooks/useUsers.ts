import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchUser,
  fetchUsers,
  searchUsers,
  updateUserHireDate,
  updateUserRoles,
  updateUserTerminationDate,
  updateUserUsageStartDate,
} from '../api/users'

export function useUsers(query?: string, perPage?: number) {
  return useQuery({
    queryKey: ['users', query ?? '', perPage ?? 'default'],
    queryFn: () => fetchUsers(query, perPage),
    placeholderData: keepPreviousData,
  })
}

/** 承認者選択(UserPicker)等、一般社員も使う軽量な検索。 */
export function useUserSearch(query?: string, perPage?: number) {
  return useQuery({
    queryKey: ['users', 'search', query ?? '', perPage ?? 'default'],
    queryFn: () => searchUsers(query, perPage),
    placeholderData: keepPreviousData,
  })
}

export function useUser(id: string) {
  return useQuery({
    queryKey: ['users', 'detail', id],
    queryFn: () => fetchUser(id),
    enabled: Boolean(id),
  })
}

export function useUpdateUserRoles() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, roleCodes }: { id: string; roleCodes: string[] }) => updateUserRoles(id, roleCodes),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['users', 'detail', id] })
    },
  })
}

export function useUpdateUserHireDate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, hireDate }: { id: string; hireDate: string }) => updateUserHireDate(id, hireDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['users', 'detail', id] })
    },
  })
}

export function useUpdateUserTerminationDate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, terminationDate }: { id: string; terminationDate: string | null }) => updateUserTerminationDate(id, terminationDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['users', 'detail', id] })
    },
  })
}

export function useUpdateUserUsageStartDate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, usageStartDate }: { id: string; usageStartDate: string }) => updateUserUsageStartDate(id, usageStartDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['users', 'detail', id] })
    },
  })
}
