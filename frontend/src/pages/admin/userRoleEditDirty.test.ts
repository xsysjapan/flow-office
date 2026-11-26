import { describe, expect, it } from 'vitest'
import { isUserDetailsDirty, type UserDetailsForm } from './userRoleEditDirty'

const base: UserDetailsForm = {
  profile: {
    name: '山田太郎',
    email: 'yamada@example.com',
    employee_number: '',
    department: '',
    job_title: '',
    employment_status: 'active',
    account_status: 'active',
  },
  hireDate: '2024-04-01',
  terminationDate: '',
  usageStartDate: '2024-04-01',
  paidLeaveAutoGrantEnabled: true,
  specialLeaveAutoGrantEnabled: true,
}

describe('isUserDetailsDirty', () => {
  it('変更なしはfalse', () => {
    expect(isUserDetailsDirty(base, { ...base, profile: { ...base.profile } })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isUserDetailsDirty(base, { ...base, profile: { ...base.profile, name: '鈴木' } })).toBe(true)
    expect(isUserDetailsDirty(base, { ...base, hireDate: '2024-05-01' })).toBe(true)
    expect(isUserDetailsDirty(base, { ...base, specialLeaveAutoGrantEnabled: false })).toBe(true)
  })
  it('元に戻すとfalse', () => {
    const changed = { ...base, terminationDate: '2026-03-31' }
    expect(isUserDetailsDirty(base, { ...changed, terminationDate: '' })).toBe(false)
  })
})
