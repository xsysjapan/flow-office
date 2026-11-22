import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { confirmNotification, fetchMyNotifications } from '../api/notifications'

export function useMyNotifications(status?: 'unread' | 'read', page = 1) {
  return useQuery({
    queryKey: ['notifications', 'mine', status ?? 'all', page],
    queryFn: () => fetchMyNotifications(status, page === 1 ? undefined : page),
    placeholderData: keepPreviousData,
  })
}

export function useConfirmNotification() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => confirmNotification(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications', 'mine'] })
    },
  })
}
