import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { confirmNotification, fetchMyNotifications } from '../api/notifications'

export function useMyNotifications(status?: 'unread' | 'read') {
  return useQuery({
    queryKey: ['notifications', 'mine', status ?? 'all'],
    queryFn: () => fetchMyNotifications(status),
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
