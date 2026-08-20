import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchAdminCommandRuns, fetchAdminCommands, runAdminCommand } from '../api/adminCommands'

export function useAdminCommands() {
  return useQuery({ queryKey: ['admin-commands'], queryFn: fetchAdminCommands })
}

export function useAdminCommandRuns() {
  return useQuery({ queryKey: ['admin-command-runs'], queryFn: fetchAdminCommandRuns, refetchInterval: 5000 })
}

export function useRunAdminCommand() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ command, parameters }: { command: string; parameters: Record<string, unknown> }) => runAdminCommand(command, parameters),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-command-runs'] }),
  })
}
