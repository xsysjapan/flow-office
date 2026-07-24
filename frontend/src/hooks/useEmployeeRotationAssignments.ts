import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignEmployeeRotation,
  fetchEmployeeRotationAssignment,
  generateRotationShiftAssignments,
  type AssignEmployeeRotationInput,
  type GenerateRotationShiftAssignmentsInput,
} from '../api/employeeRotationAssignments'

const DETAIL_KEY = (userId: string) => ['employee-rotation-assignment', userId]

export function useEmployeeRotationAssignment(userId: string | undefined) {
  return useQuery({
    queryKey: DETAIL_KEY(userId ?? ''),
    queryFn: () => fetchEmployeeRotationAssignment(userId as string),
    enabled: userId !== undefined,
  })
}

export function useAssignEmployeeRotation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: AssignEmployeeRotationInput) => assignEmployeeRotation(input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: DETAIL_KEY(variables.user_id) })
    },
  })
}

export function useGenerateRotationShiftAssignments() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GenerateRotationShiftAssignmentsInput) => generateRotationShiftAssignments(input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['employee-shift-assignments', variables.user_id] })
    },
  })
}
