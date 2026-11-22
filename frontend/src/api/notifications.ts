import { apiFetch } from './client'
import type { MyNotificationsResult, Notification } from './types'

export function fetchMyNotifications(status?: 'unread' | 'read', page?: number): Promise<MyNotificationsResult> {
  return apiFetch('/notifications/mine', { query: { status, page } })
}

export function confirmNotification(id: string): Promise<Notification> {
  return apiFetch(`/notifications/${id}/confirm`, { method: 'POST' })
}
