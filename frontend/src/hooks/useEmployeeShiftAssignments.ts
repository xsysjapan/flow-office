import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchShiftAssignments,
  generateShiftAssignments,
  type GenerateShiftAssignmentsInput,
} from '../api/employeeShiftAssignments'

export function useShiftAssignments(userId: number, from: string, to: string) {
  return useQuery({
    queryKey: ['employee-shift-assignments', userId, from, to],
    queryFn: () => fetchShiftAssignments(userId, from, to),
    enabled: Number.isFinite(userId) && !!from && !!to,
  })
}

export function useGenerateShiftAssignments() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GenerateShiftAssignmentsInput) => generateShiftAssignments(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['employee-shift-assignments', input.user_id] })
    },
  })
}
