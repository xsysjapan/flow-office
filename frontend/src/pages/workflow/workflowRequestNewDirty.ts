export interface WorkflowRequestNewValues {
  requestTypeCode: string
  title: string
  formValues: Record<string, string>
  approverUserId: string | undefined
}

/** 空欄は未入力として扱い、キーの順序に依らず比べる。 */
const normalizeFormValues = (values: Record<string, string>) =>
  JSON.stringify(
    Object.entries(values)
      .filter(([, value]) => value !== '')
      .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)),
  )

/** 申請の作成フォームに、初期値(URLクエリの初期値を含む)から変更があるか。 */
export function isWorkflowRequestNewDirty(base: WorkflowRequestNewValues, current: WorkflowRequestNewValues): boolean {
  return (
    base.requestTypeCode !== current.requestTypeCode ||
    base.title !== current.title ||
    (base.approverUserId ?? '') !== (current.approverUserId ?? '') ||
    normalizeFormValues(base.formValues) !== normalizeFormValues(current.formValues)
  )
}
