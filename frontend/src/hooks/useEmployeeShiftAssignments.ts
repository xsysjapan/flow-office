import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignShiftPatternDay,
  fetchShiftAssignments,
  generateShiftAssignments,
  publishShiftSchedule,
  reviewShiftSchedule,
  type AssignShiftPatternDayInput,
  type GenerateShiftAssignmentsInput,
  type ShiftScheduleTarget,
} from '../api/employeeShiftAssignments'

export function useShiftAssignments(userId: string, from: string, to: string) {
  return useQuery({
    queryKey: ['employee-shift-assignments', userId, from, to],
    queryFn: () => fetchShiftAssignments(userId, from, to),
    enabled: Boolean(userId) && !!from && !!to,
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

export function useAssignShiftPatternDay() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: AssignShiftPatternDayInput) => assignShiftPatternDay(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['employee-shift-assignments', input.user_id] })
    },
  })
}

export function useShiftScheduleReview(target: ShiftScheduleTarget | undefined) {
  return useQuery({
    queryKey: ['shift-schedule-review', target],
    queryFn: () => reviewShiftSchedule(target as ShiftScheduleTarget),
    enabled: !!target && !!target.year_month && (!!target.department || (target.user_ids?.length ?? 0) > 0),
  })
}

export function usePublishShiftSchedule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (target: ShiftScheduleTarget) => publishShiftSchedule(target),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['employee-shift-assignments'] })
      void queryClient.invalidateQueries({ queryKey: ['shift-schedule-review'] })
    },
  })
}
