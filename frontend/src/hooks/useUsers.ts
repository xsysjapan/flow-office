import { useQuery } from '@tanstack/react-query'
import { fetchUsers } from '../api/users'

export function useUsers(query?: string) {
  return useQuery({
    queryKey: ['users', query ?? ''],
    queryFn: () => fetchUsers(query),
  })
}
