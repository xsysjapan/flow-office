import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignBackOfficeTask,
  bulkCompleteBackOfficeTasks,
  changeBackOfficeTaskStatus,
  fetchBackOfficeTask,
  fetchMyTasks,
  fetchUnassignedTasks,
  type BackOfficeTaskListOptions,
} from '../api/backOfficeTasks'
import type { BackOfficeTaskStatus } from '../api/types'

const UNASSIGNED_KEY = ['backoffice-tasks', 'unassigned']
const MINE_KEY = ['backoffice-tasks', 'mine']

export function useUnassignedBackOfficeTasks(options: BackOfficeTaskListOptions = {}) {
  return useQuery({ queryKey: [...UNASSIGNED_KEY, options], queryFn: () => fetchUnassignedTasks(options) })
}

export function useMyBackOfficeTasks(options: BackOfficeTaskListOptions = {}, enabled = true) {
  return useQuery({ queryKey: [...MINE_KEY, options], queryFn: () => fetchMyTasks(options), enabled })
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
    void queryClient.invalidateQueries({ queryKey: UNASSIGNED_KEY })
    void queryClient.invalidateQueries({ queryKey: MINE_KEY })
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
