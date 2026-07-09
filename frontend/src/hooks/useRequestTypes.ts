import { useQuery } from '@tanstack/react-query'
import { fetchRequestTypes } from '../api/requestTypes'

export function useRequestTypes() {
  return useQuery({ queryKey: ['request-types'], queryFn: fetchRequestTypes })
}
