import { getActiveCurrency } from '@/shared/utils/currency'

export const displayFormatCurrency = (value: number | string) => {
  // Convertir a número si es string, y validar que sea un número válido
  const numValue = typeof value === 'string' ? parseFloat(value) : value

  // Si no es un número válido, usar 0
  const safeValue = isNaN(numValue) ? 0 : numValue

  // Truncar a 2 decimales sin redondear
  const truncatedValue = Math.trunc(safeValue * 100) / 100

  return truncatedValue.toLocaleString('es-MX', {
    style: 'currency',
    currency: getActiveCurrency(),
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}
