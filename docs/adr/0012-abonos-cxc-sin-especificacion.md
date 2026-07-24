# ADR-0012: Abonos y cuentas por cobrar (CxC) diferidos

- Estado: aceptado
- Fecha: 2026-07-24

## Contexto

El maestro menciona CxC cuatro veces sin definirla: como item de
lista de features (~linea 453), como exclusion explicita de la regla
de cuadre de pagos (~1248: "Sale.payments.sum = Sale.total excepto
credito/CxC"), y como "abono a apartado" (~5998, ~6263) que
pertenece a un dominio layaway igualmente inexistente. No hay DDL de
abonos, saldos por documento, estados de cuenta ni antiguedad.

Lo que SI existe hoy (frontera de lo implementado):
- Venta a credito: metodo credit en checkout, Customer.credit_balance
  como saldo acumulador, validacion de tope (canBuyOnCredit /
  InsufficientCreditException) y notificacion RN-198 (limite de
  credito) al rol COBRANZA.
- Lo que NO existe: el ciclo de cobro completo — registrar abonos,
  aplicarlos a saldo o a documentos, consultar estado de cuenta,
  reporte de antiguedad de saldos.

## Decision

Se DIFIERE el modulo de abonos/CxC. Sin DDL ni reglas en el maestro,
cualquier implementacion seria diseño de producto improvisado
(aplicacion a saldo global vs por documento, orden de aplicacion,
intereses/moratorios, recibos) sin estandar defendible.

## Criterio de reapertura

Cuando el maestro (o decision de producto documentada) defina: modelo
de aplicacion de abonos (saldo global o por documento), DDL de la
tabla de abonos/movimientos de credito, y reglas de negocio del ciclo
(recibos, cancelacion de abono, cortes). La base existente
(credit_balance + rol COBRANZA + canal de notificaciones) es el punto
de partida del slice.
