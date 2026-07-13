import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createDefaultWorkStyle,
  createWorkStyle,
  fetchWorkStyles,
  setDefaultWorkStyle,
  type CreateDefaultWorkStyleInput,
  type CreateWorkStyleInput,
} from '../api/workStyles'

const LIST_KEY = ['work-styles']

export function useWorkStyles() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchWorkStyles })
}

export function useCreateWorkStyle() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateWorkStyleInput) => createWorkStyle(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useCreateDefaultWorkStyle() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateDefaultWorkStyleInput = {}) => createDefaultWorkStyle(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useSetDefaultWorkStyle() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => setDefaultWorkStyle(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}
