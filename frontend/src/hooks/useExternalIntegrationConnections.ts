import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createExternalIntegrationConnection,
  deleteExternalIntegrationConnection,
  fetchExternalIntegrationConnections,
  updateExternalIntegrationConnection,
  type CreateExternalIntegrationConnectionInput,
  type UpdateExternalIntegrationConnectionInput,
} from '../api/externalIntegrationConnections'

const EXTERNAL_INTEGRATION_CONNECTIONS_KEY = ['external-integration-connections']

export function useExternalIntegrationConnections() {
  return useQuery({
    queryKey: EXTERNAL_INTEGRATION_CONNECTIONS_KEY,
    queryFn: fetchExternalIntegrationConnections,
  })
}

export function useCreateExternalIntegrationConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateExternalIntegrationConnectionInput) => createExternalIntegrationConnection(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: EXTERNAL_INTEGRATION_CONNECTIONS_KEY })
    },
  })
}

export function useUpdateExternalIntegrationConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: UpdateExternalIntegrationConnectionInput }) =>
      updateExternalIntegrationConnection(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: EXTERNAL_INTEGRATION_CONNECTIONS_KEY })
    },
  })
}

export function useDeleteExternalIntegrationConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => deleteExternalIntegrationConnection(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: EXTERNAL_INTEGRATION_CONNECTIONS_KEY })
    },
  })
}
