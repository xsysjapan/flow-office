import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignBackOfficeTask,
  bulkAssignBackOfficeTasks,
  bulkCompleteBackOfficeTasks,
  changeBackOfficeTaskStatus,
  fetchBackOfficeTask,
  fetchBackOfficeTasks,
  type BackOfficeTaskListOptions,
} from '../api/backOfficeTasks'
import type { BackOfficeTaskStatus } from '../api/types'

const LIST_KEY = ['backoffice-tasks', 'list']

export function useBackOfficeTasks(options: BackOfficeTaskListOptions = {}, enabled = true) {
  return useQuery({
    queryKey: [...LIST_KEY, options],
    queryFn: () => fetchBackOfficeTasks(options),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useBackOfficeTask(id: string) {
  return useQuery({
    queryKey: ['backoffice-tasks', id],
    queryFn: () => fetchBackOfficeTask(id),
    enabled: Boolean(id),
  })
}

function useInvalidateBackOfficeTasks() {
  const queryClient = useQueryClient()

  return (id?: string) => {
    void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    if (id !== undefined) {
      void queryClient.invalidateQueries({ queryKey: ['backoffice-tasks', id] })
    }
  }
}

export function useAssignBackOfficeTask() {
  const invalidate = useInvalidateBackOfficeTasks()

  return useMutation({
    mutationFn: ({ id, assignedUserId }: { id: string; assignedUserId: string }) =>
      assignBackOfficeTask(id, assignedUserId),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useChangeBackOfficeTaskStatus() {
  const invalidate = useInvalidateBackOfficeTasks()

  return useMutation({
    mutationFn: ({ id, status, comment }: { id: string; status: BackOfficeTaskStatus; comment?: string }) =>
      changeBackOfficeTaskStatus(id, status, comment),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useBulkCompleteBackOfficeTasks() {
  const invalidate = useInvalidateBackOfficeTasks()

  return useMutation({
    mutationFn: (taskIds: string[]) => bulkCompleteBackOfficeTasks(taskIds),
    onSuccess: () => invalidate(),
  })
}

export function useBulkAssignBackOfficeTasks() {
  const invalidate = useInvalidateBackOfficeTasks()

  return useMutation({
    mutationFn: ({ taskIds, assignedUserId }: { taskIds: string[]; assignedUserId: string }) =>
      bulkAssignBackOfficeTasks(taskIds, assignedUserId),
    onSuccess: () => invalidate(),
  })
}
