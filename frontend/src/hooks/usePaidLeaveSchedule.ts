import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  applyScheduledGrants,
  fetchPaidLeaveScheduleEntries,
  fetchPaidLeaveScheduleEntry,
  overridePaidLeaveScheduleEntry,
  reassessPaidLeaveScheduleEntry,
  type FetchPaidLeaveScheduleEntriesOptions,
  type OverridePaidLeaveScheduleEntryInput,
} from '../api/paidLeaveSchedule'

const LIST_KEY_ROOT = ['paid-leave', 'schedule-entries'] as const

function listKey(options: FetchPaidLeaveScheduleEntriesOptions) {
  return [...LIST_KEY_ROOT, options] as const
}

function entryKey(entryId: string) {
  return ['paid-leave', 'schedule-entries', 'entry', entryId] as const
}

export function usePaidLeaveScheduleEntries(options: FetchPaidLeaveScheduleEntriesOptions = {}) {
  return useQuery({
    queryKey: listKey(options),
    queryFn: () => fetchPaidLeaveScheduleEntries(options),
  })
}

export function usePaidLeaveScheduleEntry(entryId: string) {
  return useQuery({
    queryKey: entryKey(entryId),
    queryFn: () => fetchPaidLeaveScheduleEntry(entryId),
    enabled: Boolean(entryId),
  })
}

function useInvalidatePaidLeaveScheduleEntries() {
  const queryClient = useQueryClient()

  return (entryId?: string) => {
    void queryClient.invalidateQueries({ queryKey: LIST_KEY_ROOT })
    if (entryId) {
      void queryClient.invalidateQueries({ queryKey: entryKey(entryId) })
    }
  }
}

export function useReassessPaidLeaveScheduleEntry() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (entryId: string) => reassessPaidLeaveScheduleEntry(entryId),
    onSuccess: (data) => invalidate(data.id),
  })
}

export function useOverridePaidLeaveScheduleEntry() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: ({ entryId, input }: { entryId: string; input: OverridePaidLeaveScheduleEntryInput }) =>
      overridePaidLeaveScheduleEntry(entryId, input),
    onSuccess: (data) => invalidate(data.id),
  })
}

export function useApplyScheduledGrants() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (entryIds: string[]) => applyScheduledGrants(entryIds),
    onSuccess: () => invalidate(),
  })
}
