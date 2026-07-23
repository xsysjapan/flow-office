import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createShiftPattern,
  fetchShiftPatterns,
  updateShiftPattern,
  type ShiftPatternInput,
} from '../api/shiftPatterns'

const LIST_KEY = ['shift-patterns']

export function useShiftPatterns() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchShiftPatterns })
}

export function useCreateShiftPattern() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ShiftPatternInput) => createShiftPattern(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useUpdateShiftPattern() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Omit<ShiftPatternInput, 'code'> }) => updateShiftPattern(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}
