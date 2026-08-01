import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  excludeAttendanceSubmissionReminder,
  fetchAttendanceSubmissionReminderExclusions,
  type ExcludeAttendanceSubmissionReminderInput,
} from '../api/attendanceSubmissionReminderExclusions'

const LIST_KEY = (userId: string) => ['attendance-submission-reminder-exclusions', userId]

/** 対象社員の勤怠未提出督促の個別除外一覧(role:admin限定)。userId未確定の間は取得しない。 */
export function useAttendanceSubmissionReminderExclusions(userId: string | undefined) {
  return useQuery({
    queryKey: LIST_KEY(userId ?? ''),
    queryFn: () => fetchAttendanceSubmissionReminderExclusions(userId),
    enabled: userId !== undefined,
  })
}

export function useExcludeAttendanceSubmissionReminder() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ExcludeAttendanceSubmissionReminderInput) => excludeAttendanceSubmissionReminder(input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY(variables.user_id) })
    },
  })
}
