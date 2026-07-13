import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignUserWorkStyleForMonth,
  fetchUserWorkStyleMonthlyAssignments,
  type AssignUserWorkStyleForMonthInput,
} from '../api/userWorkStyleMonthlyAssignments'

const LIST_KEY = (userId: number) => ['user-work-style-monthly-assignments', userId]

export function useUserWorkStyleMonthlyAssignments(userId: number | undefined) {
  return useQuery({
    queryKey: LIST_KEY(userId ?? 0),
    queryFn: () => fetchUserWorkStyleMonthlyAssignments(userId as number),
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
