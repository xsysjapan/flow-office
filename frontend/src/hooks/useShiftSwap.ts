import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveShiftSwapRequest,
  cancelShiftSwapRequest,
  createShiftSwapRequest,
  fetchMyShiftSwapRequests,
  fetchShiftSwapRequestsToApprove,
  returnShiftSwapRequest,
  type CreateShiftSwapRequestInput,
} from '../api/shiftSwap'

const MY_REQUESTS_KEY = ['shift-swap', 'requests', 'mine']
const TO_APPROVE_KEY = ['shift-swap', 'requests', 'to-approve']

export function useMyShiftSwapRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMyShiftSwapRequests })
}

export function useShiftSwapRequestsToApprove() {
  return useQuery({ queryKey: TO_APPROVE_KEY, queryFn: fetchShiftSwapRequestsToApprove })
}

function useInvalidateShiftSwapRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: TO_APPROVE_KEY })
  }
}

export function useCreateShiftSwapRequest() {
  const invalidate = useInvalidateShiftSwapRequests()

  return useMutation({
    mutationFn: (input: CreateShiftSwapRequestInput) => createShiftSwapRequest(input),
    onSuccess: () => invalidate(),
  })
}

export function useApproveShiftSwapRequest() {
  const invalidate = useInvalidateShiftSwapRequests()

  return useMutation({
    mutationFn: (id: string) => approveShiftSwapRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useReturnShiftSwapRequest() {
  const invalidate = useInvalidateShiftSwapRequests()

  return useMutation({
    mutationFn: ({ id, comment }: { id: string; comment: string }) => returnShiftSwapRequest(id, comment),
    onSuccess: () => invalidate(),
  })
}

export function useCancelShiftSwapRequest() {
  const invalidate = useInvalidateShiftSwapRequests()

  return useMutation({
    mutationFn: (id: string) => cancelShiftSwapRequest(id),
    onSuccess: () => invalidate(),
  })
}
