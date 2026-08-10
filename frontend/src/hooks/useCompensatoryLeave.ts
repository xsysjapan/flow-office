import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  cancelCompensatoryLeaveRequest,
  createCompensatoryLeaveRequest,
  fetchMyCompensatoryLeaveGrants,
  fetchMyCompensatoryLeaveRequests,
  type CreateCompensatoryLeaveRequestInput,
} from '../api/compensatoryLeave'

const MY_GRANTS_KEY = ['compensatory-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['compensatory-leave', 'requests', 'mine']

export function useMyCompensatoryLeaveGrants() {
  return useQuery({ queryKey: MY_GRANTS_KEY, queryFn: fetchMyCompensatoryLeaveGrants })
}

export function useMyCompensatoryLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMyCompensatoryLeaveRequests })
}

function useInvalidateCompensatoryLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useCreateCompensatoryLeaveRequest() {
  const invalidate = useInvalidateCompensatoryLeaveRequests()

  return useMutation({
    mutationFn: (input: CreateCompensatoryLeaveRequestInput) => createCompensatoryLeaveRequest(input),
    onSuccess: () => invalidate(),
  })
}

export function useCancelCompensatoryLeaveRequest() {
  const invalidate = useInvalidateCompensatoryLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => cancelCompensatoryLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}
