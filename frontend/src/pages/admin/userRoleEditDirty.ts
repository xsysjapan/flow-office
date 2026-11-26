export interface UserDetailsForm {
  profile: {
    name: string
    email: string
    employee_number: string
    department: string
    job_title: string
    employment_status: string
    account_status: string
  }
  hireDate: string
  terminationDate: string
  usageStartDate: string
  paidLeaveAutoGrantEnabled: boolean
  specialLeaveAutoGrantEnabled: boolean
}

/** 利用者の人事情報フォームに、読み込んだ値(保存後は送った値)から変更があるか。 */
export function isUserDetailsDirty(base: UserDetailsForm, current: UserDetailsForm): boolean {
  const profileKeys = Object.keys(base.profile) as (keyof UserDetailsForm['profile'])[]
  return (
    profileKeys.some((key) => base.profile[key] !== current.profile[key]) ||
    base.hireDate !== current.hireDate ||
    base.terminationDate !== current.terminationDate ||
    base.usageStartDate !== current.usageStartDate ||
    base.paidLeaveAutoGrantEnabled !== current.paidLeaveAutoGrantEnabled ||
    base.specialLeaveAutoGrantEnabled !== current.specialLeaveAutoGrantEnabled
  )
}
