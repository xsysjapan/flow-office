import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  confirmMonthlyAttendanceDraftField,
  fetchMonthlyAttendanceDraft,
  fetchMonthlyAttendanceDraftFields,
  fetchMyMonthlyAttendanceDrafts,
  submitMonthlyAttendanceDraft,
  validateMonthlyAttendanceDraft,
} from '../api/monthlyAttendanceDrafts'

export function useMyMonthlyAttendanceDrafts() {
  return useQuery({
    queryKey: ['monthly-attendance-drafts', 'me'],
    queryFn: fetchMyMonthlyAttendanceDrafts,
  })
}

export function useMonthlyAttendanceDraft(id: number) {
  return useQuery({
    queryKey: ['monthly-attendance-drafts', id],
    queryFn: () => fetchMonthlyAttendanceDraft(id),
    enabled: Number.isFinite(id),
  })
}

export function useMonthlyAttendanceDraftFields(id: number) {
  return useQuery({
    queryKey: ['monthly-attendance-draft-fields', id],
    queryFn: () => fetchMonthlyAttendanceDraftFields(id),
    enabled: Number.isFinite(id),
  })
}

export function useValidateMonthlyAttendanceDraft(id: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => validateMonthlyAttendanceDraft(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['monthly-attendance-drafts', id] })
    },
  })
}

export function useConfirmMonthlyAttendanceDraftField(draftId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (fieldProvenanceId: number) => confirmMonthlyAttendanceDraftField(draftId, fieldProvenanceId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['monthly-attendance-draft-fields', draftId] })
    },
  })
}

export function useSubmitMonthlyAttendanceDraft(id: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (approverUserId: number) => submitMonthlyAttendanceDraft(id, approverUserId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['monthly-attendance-drafts', id] })
      void queryClient.invalidateQueries({ queryKey: ['monthly-attendance-drafts', 'me'] })
    },
  })
}
