import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuItem,
} from '@/components/ui/sidebar'

const ROW_WIDTHS = ['w-3/4', 'w-1/2', 'w-2/3', 'w-3/5', 'w-4/5', 'w-1/2', 'w-2/3']

/**
 * Placeholder visible del menú del sidebar mientras se resuelve `/api/menu`.
 * Se usa como `fallback` del <Skeleton> de boneyard-js: sin bones pre-generados
 * (no se corrió `npx boneyard-js build`), boneyard no dibuja nada por sí solo,
 * así que el fallback es lo que se ve. Las barras usan el color del propio
 * sidebar para contrastar en cualquier tema. En modo ícono solo queda el cuadro.
 */
export function NavSkeleton() {
  return (
    <SidebarGroup>
      <SidebarGroupLabel>
        <span className="block h-3 w-20 rounded bg-sidebar-foreground/10" />
      </SidebarGroupLabel>
      <SidebarMenu>
        {ROW_WIDTHS.map((width, index) => (
          <SidebarMenuItem key={index}>
            <div className="flex items-center gap-2 rounded-md p-2">
              <span className="size-4 flex-none rounded bg-sidebar-foreground/15" />
              <span
                className={`h-3.5 rounded bg-sidebar-foreground/10 group-data-[collapsible=icon]:hidden ${width}`}
              />
            </div>
          </SidebarMenuItem>
        ))}
      </SidebarMenu>
    </SidebarGroup>
  )
}
