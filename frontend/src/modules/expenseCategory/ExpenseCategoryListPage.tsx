import { expenseCategoryFormSchema, type ExpenseCategoryFormValues, type IExpenseCategory } from '@/modules/expenseCategory/schema'
import { ExpenseCategoryResource } from '@/modules/expenseCategory/services/ExpenseCategoryResource'
import { useExpenseCategoryStore } from '@/modules/expenseCategory/store/expenseCategoryStore'
import { CatalogCrudModule } from '@/shared/components/catalog/CatalogCrudModule'
import { CatalogType } from '@/shared/hooks/useCatalogs'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

export const ExpenseCategoryListPage = () => (
  <CatalogCrudModule<IExpenseCategory, ExpenseCategoryFormValues>
    title="Categorías de Egreso"
    description="Clasificación de los egresos del condominio"
    newLabel="Nueva categoría"
    editLabel="Editar categoría"
    searchPlaceholder="Buscar categorías…"
    permissionEntity="expense_category"
    store={useExpenseCategoryStore}
    resource={ExpenseCategoryResource}
    schema={expenseCategoryFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      parent_id: entity?.parent_id ?? undefined,
      order: entity?.order ?? null,
      is_active: entity?.is_active ?? true,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Mantenimiento' },
      { name: 'parent_id', label: 'Categoría padre', type: 'catalog', catalog: CatalogType.EXPENSE_CATEGORY },
      { name: 'is_active', label: 'Activa', type: 'switch', colSpan: 6 },
    ]}
    columns={[
      { key: 'parent', label: 'Padre', render: (c) => c.parent?.name ?? '—' },
      {
        key: 'is_active',
        label: 'Estado',
        render: (c) => (
          <StatusPill tone={c.is_active ? 'g' : 'r'}>{c.is_active ? 'Activa' : 'Inactiva'}</StatusPill>
        ),
      },
    ]}
    initialSearch={{
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [{ relation: 'parent' }],
    }}
  />
)
