import { describe, expect, it } from 'vitest'
import { ApiError } from '../api/client'
import { fieldErrorOf, fieldErrors, nonFieldError } from './fieldErrors'

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

describe('fieldErrors()', () => {
  const heading = '入力内容を確認してください。'

  it('of は完全一致のキーの最初のメッセージを返す', () => {
    const fe = fieldErrors(new ApiError(422, 'm', { name: ['a', 'b'], 'name.x': ['c'] }), ['name'] as const)
    expect(fe.of('name')).toBe('a')
  })

  it('ofRow は key あり・なしを引く', () => {
    const fe = fieldErrors(new ApiError(422, 'm', { 'steps.1.days': ['d'], 'steps.1': ['row'] }), [])
    expect(fe.ofRow('steps', 1, 'days')).toBe('d')
    expect(fe.ofRow('steps', 1)).toBe('row')
    expect(fe.ofRow('steps', 2, 'days')).toBeUndefined()
  })

  it('ofAny は prefix 自身と数字の要素だけを重複なく返す', () => {
    const fe = fieldErrors(
      new ApiError(422, 'm', { ids: ['self'], 'ids.0': ['x'], 'ids.1': ['x', 'y'], 'ids.a': ['no'], 'ids.0.z': ['no2'], idsx: ['no3'] }),
      [],
    )
    expect(fe.ofAny('ids')).toEqual(['self', 'x', 'y'])
  })

  it('ワイルドカードは数字1区切りだけに一致する', () => {
    const err = new ApiError(422, 'm', { 'a.0.b': ['1'], 'a.x.b': ['2'], 'a.0.1.b': ['3'], 'p.3': ['4'], 'p.x': ['5'] })
    const rest = fieldErrors(err, ['a.*.b', 'p.*']).rest as ApiError
    expect(rest.errors).toEqual({ 'a.x.b': ['2'], 'a.0.1.b': ['3'], 'p.x': ['5'] })
  })

  it('未知のキーが rest に残り、見出しが置き換わる', () => {
    const err = new ApiError(422, 'raw', { name: ['n'], unknown: ['u'] }, 'c')
    const rest = fieldErrors(err, ['name']).rest as ApiError
    expect(rest).toBeInstanceOf(ApiError)
    expect(rest.errors).toEqual({ unknown: ['u'] })
    expect(rest.message).toBe(heading)
    expect(rest.status).toBe(422)
    expect(rest.code).toBe('c')
  })

  it('全キーが fields にあれば rest は errors 空で見出しだけ', () => {
    const rest = fieldErrors(new ApiError(422, 'raw', { name: ['n'] }), ['name']).rest as ApiError
    expect(rest.errors).toEqual({})
    expect(rest.message).toBe(heading)
  })

  it('422 以外の ApiError と普通の Error はそのまま返す', () => {
    const api = new ApiError(409, 'conflict', { name: ['n'] })
    const plain = new Error('x')
    expect(fieldErrors(api, ['name']).rest).toBe(api)
    expect(fieldErrors(plain, ['name']).rest).toBe(plain)
  })

  it('null/undefined は null', () => {
    expect(fieldErrors(null, ['a']).rest).toBeNull()
    expect(fieldErrors(undefined, ['a']).rest).toBeNull()
  })
})
