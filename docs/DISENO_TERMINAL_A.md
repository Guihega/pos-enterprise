# Diseno: cobro con terminal bancaria independiente (maestro 48.6, modelo A)

- Estado: DISENADO 2026-08-27, PR en curso. Alcance del usuario: cobro con
  tarjeta via terminal del banco; modelo B (SDK integrado) fase posterior.
- Modelo A (8178-8181): el cajero opera la terminal del banco, captura en el
  POS monto y referencia; el POS no se conecta al banco.

## 1. Lo que ya existe (verificado 2026-08-27)

- Metodos card_credit / card_debit (SalePayment:37-39; StoreSaleRequest:73-74).
- sale_payments.reference, authorization_code, card_brand, card_last4,
  metadata; StoreSaleRequest:84-87 los acepta; CheckoutPayment los
  transporta; SalesService:307-310 los persiste.
- El corte de caja ya separa: solo METHOD_CASH genera CashMovement
  (SalesService:317); expected_amount = opening + movimientos de efectivo.
- sales-summary ya desglosa pagos por metodo.

## 2. Gap

Todos los datos de tarjeta son opcionales: un cobro card_credit sin numero
de autorizacion entra igual, y sin el no hay conciliacion posible con el
estado de cuenta del banco al corte.

## 3. Decision del usuario

D1 — Obligatoriedad al cobrar con card_credit/card_debit:
  (a) authorization_code obligatorio; reference, card_brand, card_last4
      opcionales.
  (b) Autorizacion + ultimos 4 obligatorios.
  (c) Nada obligatorio (cerrar el modelo A sin cambio).
  RESPUESTA: (a) aplicada por DEFECTO DEL COPILOTO ante silencio del usuario
  (leccion 39). CONFIRMAR: (b) es anadir card_last4 a la misma regla.

## 4. Regla

- required_if en StoreSaleRequest sobre payments.*.authorization_code
  cuando payments.*.method es card_credit o card_debit. Es forma pura
  (no depende de datos), por eso vive en el FormRequest y no en el
  servicio. Efectivo y demas metodos no cambian.
- Sin migracion, sin permiso, sin ruta. Endpoints 28.

## 5. Fuera

Modelo B (SDK Mercado Pago Point / Clip / Stripe Terminal): fase posterior
declarada por el usuario; requiere hardware en mano y es en gran parte
terminal/frontend. Conciliacion bancaria automatica: no esta en el maestro.
