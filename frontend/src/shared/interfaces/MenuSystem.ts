export interface IMenuModule {
  id: string
  name: string
  icon: string | null
  route: string | null
  permission_entity: string | null
  group: string | null
}

export interface IMenuItemModule {
  type: 'module'
  id: string
  name: string
  icon: string | null
  route: string | null
  permission_entity: string | null
  group: string | null
}

export interface IMenuItemGroup {
  type: 'group'
  label: string
  icon: string | null
  modules: IMenuModule[]
}

export type IMenuItem = IMenuItemModule | IMenuItemGroup

export interface IMenuSystem {
  id: string
  name: string
  slug: string
  icon: string | null
  items: IMenuItem[]
}
