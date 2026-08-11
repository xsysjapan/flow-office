import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createHolidayCalendarSource,
  disableHolidayCalendarSource,
  syncHolidayCalendarSource,
  type CreateHolidayCalendarSourceInput,
} from '../api/holidayCalendarSources'
import type { HolidayCalendarSource } from '../api/types'

/**
 * backendに一覧取得(index)エンドポイントが無いため、登録・同期・無効化の結果を
 * React Queryの`['holiday-calendar-sources']`キャッシュに直接書き込んで一覧として保持する
 * (ページを開いている間だけ有効。再読み込みすると一覧は消える)。
 */
const LIST_KEY = ['holiday-calendar-sources']

function upsert(list: HolidayCalendarSource[] | undefined, source: HolidayCalendarSource): HolidayCalendarSource[] {
  const rest = (list ?? []).filter((item) => item.id !== source.id)
  return [...rest, source]
}

export function useCreateHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateHolidayCalendarSourceInput) => createHolidayCalendarSource(input),
    onSuccess: (source) => {
      queryClient.setQueryData<HolidayCalendarSource[]>(LIST_KEY, (prev) => upsert(prev, source))
    },
  })
}

export function useSyncHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => syncHolidayCalendarSource(id),
    onSuccess: (source) => {
      queryClient.setQueryData<HolidayCalendarSource[]>(LIST_KEY, (prev) => upsert(prev, source))
    },
  })
}

export function useDisableHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => disableHolidayCalendarSource(id),
    onSuccess: (source) => {
      queryClient.setQueryData<HolidayCalendarSource[]>(LIST_KEY, (prev) => upsert(prev, source))
    },
  })
}

export function useHolidayCalendarSourcesList() {
  return useQuery<HolidayCalendarSource[]>({
    queryKey: LIST_KEY,
    queryFn: () => Promise.resolve([]),
    initialData: [],
    staleTime: Infinity,
  })
}
