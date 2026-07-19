import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchSystemSettings, updateSystemSettings } from '../api/systemSettings'
import type { UpdateSystemSettingsInput } from '../api/types'

const SYSTEM_SETTINGS_KEY = ['system-settings']

export function useSystemSettings() {
  return useQuery({ queryKey: SYSTEM_SETTINGS_KEY, queryFn: fetchSystemSettings })
}

export function useUpdateSystemSettings() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: UpdateSystemSettingsInput) => updateSystemSettings(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: SYSTEM_SETTINGS_KEY })
    },
  })
}
