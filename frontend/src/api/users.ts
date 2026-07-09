import { apiFetch } from './client'
import type { Paginated, User } from './types'

export function fetchUsers(query?: string): Promise<Paginated<User>> {
  return apiFetch('/users', { query: { q: query } })
}
