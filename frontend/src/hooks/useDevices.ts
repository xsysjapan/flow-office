import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  disableDevice,
  fetchDevices,
  grantDeviceScope,
  issueDevicePairingCode,
  registerDevice,
  revokeDevice,
  type RegisterDeviceInput,
} from '../api/devices'
import type { DeviceScopeType } from '../api/types'

export function useDevices(ownerType?: 'organization_shared' | 'personal') {
  return useQuery({
    queryKey: ['devices', ownerType],
    queryFn: () => fetchDevices(ownerType),
  })
}

export function useRegisterDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RegisterDeviceInput) => registerDevice(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useIssueDevicePairingCode() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (deviceId: number) => issueDevicePairingCode(deviceId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useDisableDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (deviceId: number) => disableDevice(deviceId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useRevokeDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ deviceId, reason }: { deviceId: number; reason?: string }) => revokeDevice(deviceId, reason),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useGrantDeviceScope() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ deviceId, scope }: { deviceId: number; scope: DeviceScopeType }) =>
      grantDeviceScope(deviceId, scope),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}
