import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  deleteDevice,
  disableDevice,
  fetchDevices,
  grantDeviceScope,
  issueDevicePairingClaim,
  registerDevice,
  revokeDevice,
  type FetchDevicesOptions,
  type RegisterDeviceInput,
} from '../api/devices'
import type { DeviceScopeType } from '../api/types'

export function useDevices({ ownerType, page = 1, withTrashed = false }: FetchDevicesOptions = {}) {
  return useQuery({
    queryKey: ['devices', ownerType, page, withTrashed],
    queryFn: () => fetchDevices({ ownerType, page, withTrashed }),
    placeholderData: keepPreviousData,
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

export function useIssueDevicePairingClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (deviceId: number) => issueDevicePairingClaim(deviceId),
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

export function useDeleteDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (deviceId: number) => deleteDevice(deviceId),
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
