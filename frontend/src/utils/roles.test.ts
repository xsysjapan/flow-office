import { describe, expect, it } from 'vitest'
import { hasAnyRole, ROLE } from './roles'

describe('hasAnyRole', () => {
  it('returns true when the user has one of the listed roles', () => {
    expect(hasAnyRole(['employee', ROLE.ADMIN], [ROLE.ADMIN, ROLE.HR_STAFF])).toBe(true)
  })

  it('returns false when the user has none of the listed roles', () => {
    expect(hasAnyRole([ROLE.EMPLOYEE], [ROLE.ADMIN, ROLE.HR_STAFF])).toBe(false)
  })

  it('returns false when the user has no roles at all', () => {
    expect(hasAnyRole(undefined, [ROLE.ADMIN])).toBe(false)
  })
})
