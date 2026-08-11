import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createCalendarBulkOperation,
  fetchCalendarBulkOperations,
  previewCalendarBulkOperation,
  revertCalendarBulkOperation,
  type CalendarBulkOperationRequest,
} from '../api/calendarBulkOperations'

const LIST_KEY = ['calendar-bulk-operations']

export function useCalendarBulkOperations() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchCalendarBulkOperations })
}

export function usePreviewCalendarBulkOperation() {
  return useMutation({
    mutationFn: (input: CalendarBulkOperationRequest) => previewCalendarBulkOperation(input),
  })
}

export function useCreateCalendarBulkOperation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CalendarBulkOperationRequest) => createCalendarBulkOperation(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useRevertCalendarBulkOperation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => revertCalendarBulkOperation(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}
