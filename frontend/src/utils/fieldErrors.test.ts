import { describe, expect, it } from 'vitest'
import { ApiError } from '../api/client'
import { fieldErrorOf, nonFieldError } from './fieldErrors'

describe('fieldErrors', () => {
  const error = new ApiError(422, 'invalid', { 'profile.email': ['bad'], other: ['x'] })

  it('キーに対応する最初のメッセージを返す', () => {
    expect(fieldErrorOf(error, 'profile.email')).toBe('bad')
    expect(fieldErrorOf(error, 'hire_date')).toBeUndefined()
    expect(fieldErrorOf(new Error('x'), 'hire_date')).toBeUndefined()
  })

  it('項目に対応しないエラーだけを残す', () => {
    const rest = nonFieldError(error, ['profile.email']) as ApiError
    expect(rest.errors).toEqual({ other: ['x'] })
  })

  it('全て項目に対応していれば null、キーが無ければそのまま返す', () => {
    expect(nonFieldError(new ApiError(422, 'm', { a: ['1'] }), ['a'])).toBeNull()
    const plain = new ApiError(500, 'boom')
    expect(nonFieldError(plain, ['a'])).toBe(plain)
    expect(nonFieldError(null, ['a'])).toBeNull()
  })
})
