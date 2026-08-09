export interface IOnboardingItem {
  id: string
  title: string
  description: string
  details: string
  route: string
  required: boolean
  completed: boolean
  count: number
}

export interface IOnboardingChecklist {
  items: IOnboardingItem[]
  required_total: number
  required_completed: number
  progress_percentage: number
  is_complete: boolean
}
