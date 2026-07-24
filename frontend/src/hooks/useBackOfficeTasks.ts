import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  assignBackOfficeTask,
  changeBackOfficeTaskStatus,
  fetchBackOfficeTask,
  fetchMyTasks,
  fetchUnassignedTasks,
} from '../api/backOfficeTasks'
import type { BackOfficeTaskStatus } from '../api/types'

const UNASSIGNED_KEY = ['backoffice-tasks', 'unassigned']
const MINE_KEY = ['backoffice-tasks', 'mine']

export function useUnassignedBackOfficeTasks() {
  return useQuery({ queryKey: UNASSIGNED_KEY, queryFn: fetchUnassignedTasks })
}

export function useMyBackOfficeTasks() {
  return useQuery({ queryKey: MINE_KEY, queryFn: fetchMyTasks })
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
