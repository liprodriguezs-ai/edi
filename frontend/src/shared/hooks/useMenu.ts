import { apiClient } from '@/shared/api/apiClient'
import type { IMenuSystem } from '@/shared/interfaces/MenuSystem'
import { useQuery } from '@tanstack/react-query'

async function fetchMenu(): Promise<IMenuSystem[]> {
  const response = await apiClient.get<{ data: IMenuSystem[] }>('/menu')
  return response.data.data
}

export function useMenu() {
  const query = useQuery({
    queryKey: ['menu'],
    queryFn: fetchMenu,
    staleTime: 5 * 60 * 1000,
    refetchOnWindowFocus: false,
  })

  return {
    systems: query.data ?? [],
    isLoading: query.isLoading,
    refetch: query.refetch,
  }
}
