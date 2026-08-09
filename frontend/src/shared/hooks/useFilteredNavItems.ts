import { useMemo } from 'react';
import { useStore } from 'zustand';
import { sessionStore } from '@/shared/auth/sessionStore';
import type { ERoleUserSlug } from '@/shared/interfaces/Entity';

export interface NavSubItem {
  title: string;
  url: string;
  icon?: React.ComponentType<{ className?: string }>;
  permissionEntity?: string;
  roles?: ERoleUserSlug[];
  group?: string;
}

export interface NavItem {
  title: string;
  url: string;
  icon?: React.ComponentType;
  isActive?: boolean;
  permissionEntity?: string;
  roles?: ERoleUserSlug[];
  items?: NavSubItem[];
}

export interface NavSection {
  label?: string;
  items: NavItem[];
}

const EMPTY_ABILITIES: string[] = [];
const EMPTY_ROLES: ERoleUserSlug[] = [];

function hasEntityAccess(abilities: string[], entity: string): boolean {
  return abilities.includes('*') || abilities.includes(`read.${entity}`) || abilities.includes(`report.${entity}`);
}

function hasRoleAccess(userRoles: ERoleUserSlug[], requiredRoles: ERoleUserSlug[]): boolean {
  return requiredRoles.some((role) => userRoles.includes(role));
}

function isItemVisible(
  item: Pick<NavItem, 'permissionEntity' | 'roles'>,
  abilities: string[],
  userRoles: ERoleUserSlug[],
): boolean {
  if (item.roles?.length && !hasRoleAccess(userRoles, item.roles)) return false;
  if (item.permissionEntity && !hasEntityAccess(abilities, item.permissionEntity)) return false;

  return true;
}

export function useFilteredNavItems(items: NavItem[]): NavItem[] {
  const abilities = useStore(sessionStore, (state) => state.profile?.abilities ?? EMPTY_ABILITIES);
  const userRoles = useStore(sessionStore, (state) => state.profile?.roles ?? EMPTY_ROLES);

  return useMemo(() => {
    return items.reduce<NavItem[]>((acc, item) => {
      if (item.items?.length) {
        const filteredChildren = item.items.filter((child) =>
          isItemVisible(child, abilities, userRoles),
        );

        if (filteredChildren.length > 0) {
          acc.push({ ...item, items: filteredChildren });
        }

        return acc;
      }

      if (isItemVisible(item, abilities, userRoles)) {
        acc.push(item);
      }

      return acc;
    }, []);
  }, [items, abilities, userRoles]);
}

export function useFilteredNavSections(sections: NavSection[]): NavSection[] {
  const abilities = useStore(sessionStore, (state) => state.profile?.abilities ?? EMPTY_ABILITIES);
  const userRoles = useStore(sessionStore, (state) => state.profile?.roles ?? EMPTY_ROLES);

  return useMemo(() => {
    return sections.reduce<NavSection[]>((acc, section) => {
      const filteredItems = section.items.reduce<NavItem[]>((itemAcc, item) => {
        if (item.items?.length) {
          const filteredChildren = item.items.filter((child) =>
            isItemVisible(child, abilities, userRoles),
          );
          if (filteredChildren.length > 0) {
            itemAcc.push({ ...item, items: filteredChildren });
          }
        } else if (isItemVisible(item, abilities, userRoles)) {
          itemAcc.push(item);
        }
        return itemAcc;
      }, []);

      if (filteredItems.length > 0) {
        acc.push({ ...section, items: filteredItems });
      }
      return acc;
    }, []);
  }, [sections, abilities, userRoles]);
}
