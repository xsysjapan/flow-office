import { useQuery } from '@tanstack/react-query'
import { fetchRoles } from '../api/roles'

export function useRoles() {
  return useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
}
