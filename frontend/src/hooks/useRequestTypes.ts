import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createRequestType,
  fetchRequestTypes,
  updateRequestType,
  type SaveRequestTypeInput,
} from '../api/requestTypes'

export function useRequestTypes(includeInactive = false) {
  return useQuery({
    queryKey: ['request-types', includeInactive],
    queryFn: () => fetchRequestTypes(includeInactive),
  })
}

export function useCreateRequestType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveRequestTypeInput) => createRequestType(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['request-types'] })
    },
  })
}

export function useUpdateRequestType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: SaveRequestTypeInput }) => updateRequestType(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['request-types'] })
    },
  })
}
