/**
 * Formatea un monto en pesos mexicanos (MXN) con 2 decimales.
 * Centraliza el formato antes duplicado en los componentes del POS (deuda 17).
 */
export function formatPrice(value: number): string {
  return value.toLocaleString('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
  })
}
