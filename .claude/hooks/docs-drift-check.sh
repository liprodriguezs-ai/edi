#!/bin/bash
# docs-drift-check.sh — avisa cuando un cambio pudo dejar el manual desactualizado.
#
# Se dispara en el evento Stop. Mira qué archivos están modificados en los
# submódulos `backend/` y `frontend/` y, si alguno cae en una ruta que respalda
# documentación publicada, imprime qué páginas de /docs conviene revisar.
#
# NO escribe nada, NO bloquea y NO falla nunca: solo avisa. Actualizar la
# documentación es una decisión del humano, que puede lanzar @docs-writer.
#
# Ver: landing/DOCS-STYLE-GUIDE.md y .claude/agent-memory/docs-writer/

set -uo pipefail

# Silenciarlo por completo: export KIVO7_SKIP_DOCS_DRIFT=1
if [ "${KIVO7_SKIP_DOCS_DRIFT:-0}" = "1" ]; then
  exit 0
fi

# La raíz del superproyecto es dos niveles arriba de este script
# (.claude/hooks/ → .claude/ → raíz), sin depender del directorio de trabajo.
script_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P) || exit 0
repo_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd -P) || exit 0

# Sin los submódulos esperados no hay nada que revisar.
[ -d "${repo_root}/backend" ] || exit 0
[ -d "${repo_root}/frontend" ] || exit 0

command -v git >/dev/null 2>&1 || exit 0

# --- archivos modificados en los submódulos ---------------------------------
changed=""
for sub in backend frontend; do
  sub_path="${repo_root}/${sub}"
  [ -d "${sub_path}/.git" ] || [ -f "${sub_path}/.git" ] || continue
  # --porcelain da "XY ruta"; nos quedamos con la ruta y le anteponemos el submódulo.
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    path=${line:3}
    # Los renombres vienen como "viejo -> nuevo"; nos interesa el destino.
    case "$path" in *' -> '*) path=${path##* -> } ;; esac
    changed="${changed}${sub}/${path}"$'\n'
  done < <(git -C "$sub_path" status --porcelain --untracked-files=all 2>/dev/null || true)
done

[ -n "${changed//[$'\n' ]/}" ] || exit 0

# --- rutas sensibles → qué documentación tocan ------------------------------
# Formato: <patrón grep -E><TAB><qué revisar>
# Separador TAB a propósito: los patrones llevan alternancias con '|' adentro.
# El mapa completo vive en .claude/agent-memory/docs-writer/topics/mapa-pagina-codigo.md
rules=(
  $'backend/database/seeders/RolePermissionTableSeeder\\.php\tTODA la sección /docs/roles y los bloques «Quién puede usarlo» de cada página'
  $'backend/database/seeders/csv/modules\\.csv\tLos nombres del menú: /docs/empezar/como-se-organiza y cada página que los cite'
  $'backend/database/seeders/CatalogSeeder\\.php\tEtiquetas de estados centrales: cargos, pagos, lotes, reservaciones, visitantes, paquetes'
  $'backend/database/seeders/TenantCatalogSeeder\\.php\tCatálogos por tenant: /docs/estructura/tipos-y-estados-de-unidad y categorías'
  $'backend/Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder\\.php\t/docs/cuenta/planes-y-precios y /docs/empezar/crear-cuenta-y-plan'
  $'frontend/src/App\\.tsx\tRutas y permisos de pantalla citados en el manual · revisa también frontend/src/shared/docs/docsRegistry.ts'
  $'frontend/src/shared/docs/docsRegistry\\.ts\tEl registro de enlaces app → manual (corre scripts/check-docs-links.mjs)'
  $'frontend/src/modules/configuration/configSections\\.tsx\tTODA la sección /docs/configuracion'
  $'frontend/src/modules/(charge|chargeBatch|chargeConcept|chargePolicy|payment|collections)/\t/docs/cobranza/**'
  $'backend/Modules/Collections/\t/docs/cobranza/**'
  $'frontend/src/modules/import/\t/docs/cobranza/importacion-de-pagos'
  $'backend/Modules/DataImport/\t/docs/cobranza/importacion-de-pagos'
  $'frontend/src/modules/(bankAccount|expense|expenseCategory|provider)/\t/docs/tesoreria/**'
  $'backend/Modules/(Treasury|Providers)/\t/docs/tesoreria/**'
  $'frontend/src/modules/(unit|unitUser|unitType|unitStatus|unitFeature|tower|section|vehicle|userProfile|residentInvitation)/\t/docs/estructura/**'
  $'backend/Modules/Housing/\t/docs/estructura/**'
  $'frontend/src/modules/access/\t/docs/accesos/**'
  $'backend/Modules/Access/\t/docs/accesos/**'
  $'frontend/src/modules/package/\t/docs/paqueteria/**'
  $'backend/Modules/Package/\t/docs/paqueteria/**'
  $'frontend/src/modules/(amenity|reservation)/\t/docs/amenidades/**'
  $'backend/Modules/AmenityReservations/\t/docs/amenidades/**'
  $'frontend/src/modules/(announcement|chat|notification)/\t/docs/comunicacion/**'
  $'backend/Modules/(Announcements|Chat|Notification)/\t/docs/comunicacion/**'
  $'frontend/src/modules/communityProject/\t/docs/proyectos/**'
  $'backend/Modules/Projects/\t/docs/proyectos/**'
  $'frontend/src/modules/complaint/\t/docs/incidencias/**'
  $'backend/Modules/Complaint/\t/docs/incidencias/**'
  $'frontend/src/modules/(boardPeriod|collaborator)/\t/docs/mesa-y-personal/**'
  $'backend/Modules/(Board|Collaborator)/\t/docs/mesa-y-personal/**'
  $'frontend/src/modules/(reports|dashboards)/\t/docs/reportes/**'
  $'backend/Modules/Reports/\t/docs/reportes/**'
  $'frontend/src/modules/(user|checkoutSubscription|billingPackage)/\t/docs/cuenta/**'
)

hits=""
for rule in "${rules[@]}"; do
  pattern=${rule%%$'\t'*}
  target=${rule#*$'\t'}
  if printf '%s' "$changed" | grep -qE "$pattern"; then
    case "$hits" in
      *"$target"*) ;;                       # ya lo listamos
      *) hits="${hits}  · ${target}"$'\n' ;;
    esac
  fi
done

# --- enlaces de la app hacia el manual ---------------------------------------
# Barato (~50 ms) y atrapa el problema clásico: alguien renombra una página del
# manual y el botón de ayuda de la app queda apuntando a un 404.
link_report=''
if [ -x "${repo_root}/scripts/check-docs-links.mjs" ] && command -v node >/dev/null 2>&1; then
  if ! link_report=$(node "${repo_root}/scripts/check-docs-links.mjs" 2>&1); then
    printf '\n🔗 Hay enlaces de la app que apuntan a documentación inexistente:\n'
    printf '%s\n' "$link_report"
  else
    link_report=''
  fi
fi

[ -n "$hits" ] || exit 0

printf '\n📘 La documentación de /docs pudo quedar desactualizada.\n\n'
printf 'Cambiaste código que respalda estas páginas:\n\n%s' "$hits"
printf '\nSi el cambio altera lo que ve o hace el usuario, lanza @docs-writer\n'
printf 'para actualizarlas. Si es interno y no cambia nada de cara al usuario,\n'
printf 'ignora este aviso.\n\n'
printf '(Para silenciarlo: export KIVO7_SKIP_DOCS_DRIFT=1)\n\n'

exit 0
