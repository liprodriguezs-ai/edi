import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { ICatalogItem } from '@/shared/hooks/useCatalogs'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'

interface MassageGridProps {
  bodyZones: ICatalogItem[]
  massageMovements: ICatalogItem[]
  hasMovement: (_bodyZoneId: string, _movementId: string) => boolean
  toggleMovement: (_bodyZoneId: string, _movementId: string) => void
  disabled: boolean
}

/** REQ-SS-003 — grid zona corporal x movimiento; cada casilla es un par (body_zone_id, massage_movement_id). */
export function MassageGrid({ bodyZones, massageMovements, hasMovement, toggleMovement, disabled }: MassageGridProps) {
  if (bodyZones.length === 0 || massageMovements.length === 0) {
    return (
      <InlineEmpty>Configura zonas corporales y movimientos de masaje para habilitar el cuadro.</InlineEmpty>
    )
  }

  return (
    <div className="overflow-x-auto">
      <Table className="border border-border [&_td]:border-r [&_td]:border-border [&_td:last-child]:border-r-0 [&_th]:border-r [&_th]:border-border [&_th:last-child]:border-r-0">
        <TableHeader>
          <TableRow>
            <TableHead scope="col">Zona / Movimiento</TableHead>
            {massageMovements.map((m) => (
              <TableHead key={m.id} scope="col" className="text-center">
                {m.name}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {bodyZones.map((zone) => (
            <TableRow key={zone.id}>
              <TableCell className="font-medium">{zone.name}</TableCell>
              {massageMovements.map((movement) => (
                <TableCell key={movement.id} className="text-center">
                  <Checkbox
                    checked={hasMovement(zone.id, movement.id)}
                    onCheckedChange={() => toggleMovement(zone.id, movement.id)}
                    disabled={disabled}
                    aria-label={`${zone.name} - ${movement.name}`}
                  />
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
