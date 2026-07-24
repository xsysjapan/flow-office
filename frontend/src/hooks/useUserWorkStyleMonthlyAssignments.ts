import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignUserWorkStyleForMonth,
  fetchUserWorkStyleMonthlyAssignments,
  removeUserWorkStyleMonthlyAssignment,
  type AssignUserWorkStyleForMonthInput,
} from '../api/userWorkStyleMonthlyAssignments'

const LIST_KEY = (userId: string) => ['user-work-style-monthly-assignments', userId]

export function useUserWorkStyleMonthlyAssignments(userId: string | undefined) {
  return useQuery({
    queryKey: LIST_KEY(userId ?? ''),
    queryFn: () => fetchUserWorkStyleMonthlyAssignments(userId as string),
    enabled: userId !== undefined,
  })
}

export function useAssignUserWorkStyleForMonth() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: AssignUserWorkStyleForMonthInput) => assignUserWorkStyleForMonth(input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY(variables.user_id) })
    },
  })
}

export function useRemoveUserWorkStyleMonthlyAssignment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string; userId: string }) => removeUserWorkStyleMonthlyAssignment(id),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY(variables.userId) })
    },
  })
}
