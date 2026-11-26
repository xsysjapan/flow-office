import type { RequestFormFieldSchema, RequestType } from '../../api/types'

export interface RequestTypeFormValues {
  code: string
  name: string
  description: string
  requiresAttachment: boolean
  attachmentMaxSizeKb: string
  attachmentAllowedExtensions: string
  eligibleRoleCodes: string
  requiresBackOfficeTask: boolean
  backOfficeTaskType: string
  backOfficeDepartment: string
  exportAmountField: string
  allowedStatusTransitionsJson: string
  isActive: boolean
  formSchema: RequestFormFieldSchema[]
}

export const INITIAL_REQUEST_TYPE_FORM: RequestTypeFormValues = {
  code: '',
  name: '',
  description: '',
  requiresAttachment: false,
  attachmentMaxSizeKb: '',
  attachmentAllowedExtensions: '',
  eligibleRoleCodes: '',
  requiresBackOfficeTask: false,
  backOfficeTaskType: '',
  backOfficeDepartment: '',
  exportAmountField: '',
  allowedStatusTransitionsJson: '',
  isActive: true,
  formSchema: [],
}

/** 読み込んだ申請種別から、フォームの値(比べる元)を作る。 */
export function requestTypeToForm(type: RequestType): RequestTypeFormValues {
  return {
    code: type.code,
    name: type.name,
    description: type.description ?? '',
    requiresAttachment: type.requires_attachment,
    attachmentMaxSizeKb: type.attachment_max_size_kb ? String(type.attachment_max_size_kb) : '',
    attachmentAllowedExtensions: (type.attachment_allowed_extensions ?? []).join(', '),
    eligibleRoleCodes: (type.eligible_role_codes ?? []).join(', '),
    requiresBackOfficeTask: type.requires_backoffice_task,
    backOfficeTaskType: type.backoffice_task_type ?? '',
    backOfficeDepartment: type.backoffice_department ?? '',
    exportAmountField: type.export_amount_field ?? '',
    allowedStatusTransitionsJson: type.allowed_status_transitions
      ? JSON.stringify(type.allowed_status_transitions, null, 2)
      : '',
    isActive: type.is_active,
    formSchema: type.form_schema,
  }
}

const normalizeSchema = (schema: RequestFormFieldSchema[]) =>
  JSON.stringify(schema.map((field) => [field.key, field.label, field.type, field.required ?? false]))

/** 申請種別フォームに、比べる元(読み込んだ値。新規なら初期値)から変更があるか。 */
export function isRequestTypeFormDirty(base: RequestTypeFormValues, current: RequestTypeFormValues): boolean {
  const { formSchema: baseSchema, ...baseRest } = base
  const { formSchema: currentSchema, ...currentRest } = current
  return (
    (Object.keys(baseRest) as (keyof typeof baseRest)[]).some((key) => baseRest[key] !== currentRest[key]) ||
    normalizeSchema(baseSchema) !== normalizeSchema(currentSchema)
  )
}
