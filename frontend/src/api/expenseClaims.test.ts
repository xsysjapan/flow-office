import { afterEach, describe, expect, it, vi } from 'vitest'
import { addExpenseItem, addExpenseItemsBulk } from './expenseClaims'

function mockFetch() {
  const fetchMock = vi.fn().mockImplementation(async () =>
    new Response('{}', { status: 201, headers: { 'content-type': 'application/json' } }),
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const input = { category_id: 2, usage_date: '2026-07-10', amount: 12000, attributes: { nights: 1 } }

describe('expenseClaims api (明細と添付を1回で送る)', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('sends JSON when there are no files', async () => {
    const fetchMock = mockFetch()
    await addExpenseItem('c1', input)
    const init = fetchMock.mock.calls[0][1] as RequestInit
    expect(typeof init.body).toBe('string')
    await addExpenseItemsBulk('c1', [input], [[]])
    expect(typeof (fetchMock.mock.calls[1][1] as RequestInit).body).toBe('string')
  })

  it('sends multipart with files[] for a single item (attributes as a JSON string)', async () => {
    const fetchMock = mockFetch()
    const file = new File(['x'], 'r.pdf')
    await addExpenseItem('c1', input, [file])
    const body = (fetchMock.mock.calls[0][1] as RequestInit).body as FormData
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('amount')).toBe('12000')
    expect(body.get('attributes')).toBe('{"nights":1}')
    expect(body.getAll('files[]')).toEqual([file])
  })

  it('sends multipart with items as JSON and files[{row}][] for bulk', async () => {
    const fetchMock = mockFetch()
    const file = new File(['x'], 'r.pdf')
    await addExpenseItemsBulk('c1', [input, input], [[], [file]])
    const body = (fetchMock.mock.calls[0][1] as RequestInit).body as FormData
    expect(JSON.parse(body.get('items') as string)).toHaveLength(2)
    expect(body.getAll('files[1][]')).toEqual([file])
    expect(body.has('files[0][]')).toBe(false)
  })
})
