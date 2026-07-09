export interface User {
  id: number
  name: string
  email: string
  department: string | null
  job_title: string | null
  employment_status: string
  roles?: string[]
  last_login_at: string | null
}

export interface RequestType {
  id: number
  code: string
  name: string
  description: string | null
  form_schema: FormField[]
  requires_backoffice_task: boolean
  backoffice_task_type: string | null
  is_active: boolean
}

export interface FormField {
  key: string
  label: string
  type: 'text' | 'number' | 'date'
  required?: boolean
}

export type WorkflowRequestStatus = 'draft' | 'submitted' | 'approved' | 'returned' | 'cancelled'

export interface WorkflowRequest {
  id: number
  title: string
  status: WorkflowRequestStatus
  form_data: Record<string, unknown>
  request_type?: RequestType
  applicant?: User
  approver?: User
  submitted_at: string | null
  approved_at: string | null
  returned_at: string | null
  cancelled_at: string | null
  created_at: string | null
}

export type AttendanceDayStatus = 'not_started' | 'working' | 'on_break' | 'clocked_out'

export interface AttendanceBreak {
  id: number
  break_start_at: string | null
  break_end_at: string | null
}

export interface AttendanceDailyCalculation {
  planned_work_minutes: number
  actual_work_minutes: number
  prescribed_work_minutes: number
  non_statutory_overtime_minutes: number
  statutory_overtime_minutes: number
  late_night_minutes: number
  legal_holiday_work_minutes: number
  company_holiday_work_minutes: number
  legal_holiday_late_night_minutes: number
}

export interface AttendanceDay {
  id: number
  user_id: number
  work_date: string
  status: AttendanceDayStatus
  actual_start_at: string | null
  actual_end_at: string | null
  work_type: string | null
  note: string | null
  is_locked: boolean
  breaks: AttendanceBreak[]
  calculation: AttendanceDailyCalculation | null
  planned_start_at?: string | null
  planned_end_at?: string | null
}

export type AttendanceMonthStatus = 'not_submitted' | 'submitted' | 'approved' | 'returned' | 'closed'

export interface AttendanceMonth {
  id: number
  user_id: number
  year_month: string
  status: AttendanceMonthStatus
  approver?: User
  submitted_at: string | null
  approved_at: string | null
  returned_at: string | null
  closed_at: string | null
  snapshot: Record<string, number> | null
}

export type BackOfficeTaskStatus =
  | 'not_started'
  | 'in_review'
  | 'needs_fix'
  | 'processing'
  | 'ordered'
  | 'payment_scheduled'
  | 'shipped'
  | 'completed'
  | 'cancelled'

export interface BackOfficeTask {
  id: number
  source_type: string
  source_id: number
  task_type: string
  title: string
  status: BackOfficeTaskStatus
  assigned_department: string | null
  assignee?: User
  due_on: string | null
  completed_at: string | null
  created_at: string | null
}

export interface PaidLeaveGrant {
  id: number
  user_id: number
  granted_on: string
  expires_on: string
  granted_days: number
  used_days: number
  remaining_days: number
  grant_reason: string | null
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    total: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}
