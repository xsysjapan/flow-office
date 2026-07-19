import { apiFetch } from './client'
import type { Notification, Paginated } from './types'

export function fetchMyNotifications(status?: 'unread' | 'read'): Promise<Paginated<Notification>> {
  return apiFetch('/notifications/mine', { query: { status } })
}

export function confirmNotification(id: string): Promise<Notification> {
  return apiFetch(`/notifications/${id}/confirm`, { method: 'POST' })
}
