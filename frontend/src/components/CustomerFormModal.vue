<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useCustomersStore } from '@/stores/customers'
import type { Customer, CustomerInput } from '@/lib/api/generated'

const props = defineProps<{ customer?: Customer | null }>()
const emit = defineEmits<{ saved: [customer: Customer]; cancel: [] }>()

const store = useCustomersStore()

const isEdit = computed(() => props.customer != null)
const errorMessage = ref<string | null>(null)

// Estado del formulario. El cliente se LEE anidado (Customer) y se ESCRIBE
// plano (CustomerInput); este form es el puente entre ambos shapes.
// El campo monetario credit_limit usa string + inputmode decimal
// (v-model.number lanza TypeError silencioso al parsear).
const form = reactive({
  code: '',
  type: 'individual' as 'individual' | 'business',
  name: '',
  legal_name: '',
  tax_id: '',
  email: '',
  phone: '',
  mobile: '',
  address_line: '',
  city: '',
  state: '',
  postal_code: '',
  country_code: '',
  credit_limit: '',
  is_active: true,
  is_blocked: false,
  blocked_reason: '',
  notes: '',
})

onMounted(() => {
  if (props.customer) {
    const c = props.customer
    form.code = c.code ?? ''
    form.type = c.type
    form.name = c.name
    form.legal_name = c.legal_name ?? ''
    form.tax_id = c.tax.tax_id ?? ''
    form.email = c.contact.email ?? ''
    form.phone = c.contact.phone ?? ''
    form.mobile = c.contact.mobile ?? ''
    form.address_line = c.address.line ?? ''
    form.city = c.address.city ?? ''
    form.state = c.address.state ?? ''
    form.postal_code = c.address.postal_code ?? ''
    form.country_code = c.address.country_code ?? ''
    form.credit_limit = c.credit.limit != null ? String(c.credit.limit) : ''
    form.is_active = c.flags.is_active
    form.is_blocked = c.flags.is_blocked
    form.blocked_reason = c.flags.blocked_reason ?? ''
    form.notes = c.notes ?? ''
  }
})

const canSubmit = computed(
  () => form.name.trim() !== '' && form.type !== undefined && !store.saving,
)

/** Parsea un string monetario a number; null si vacio o invalido. */
function parseMoney(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const n = Number(trimmed)
  return Number.isFinite(n) ? n : null
}

async function onSubmit(): Promise<void> {
  if (!canSubmit.value) return
  errorMessage.value = null

  let creditLimit: number | null = null
  if (form.credit_limit.trim() !== '') {
    creditLimit = parseMoney(form.credit_limit)
    if (creditLimit === null || creditLimit < 0) {
      errorMessage.value = 'El limite de credito debe ser un numero valido mayor o igual a 0.'
      return
    }
  }

  const payload: CustomerInput = {
    code: form.code.trim() || null,
    type: form.type,
    name: form.name.trim(),
    legal_name: form.legal_name.trim() || null,
    tax_id: form.tax_id.trim() || null,
    email: form.email.trim() || null,
    phone: form.phone.trim() || null,
    mobile: form.mobile.trim() || null,
    address_line: form.address_line.trim() || null,
    city: form.city.trim() || null,
    state: form.state.trim() || null,
    postal_code: form.postal_code.trim() || null,
    country_code: form.country_code.trim() || null,
    credit_limit: creditLimit,
    is_active: form.is_active,
    is_blocked: form.is_blocked,
    blocked_reason: form.is_blocked ? form.blocked_reason.trim() || null : null,
    notes: form.notes.trim() || null,
  }

  const result = props.customer
    ? await store.update(props.customer.uuid, payload)
    : await store.create(payload)

  if (result.ok && result.customer) {
    emit('saved', result.customer)
  } else {
    errorMessage.value = result.errorMessage ?? 'No se pudo guardar el cliente.'
  }
}
</script>

<template>
  <div class="cust-modal__backdrop" @click.self="emit('cancel')">
    <div class="cust-modal" role="dialog" aria-modal="true">
      <header class="cust-modal__header">
        <h2>{{ isEdit ? 'Editar cliente' : 'Nuevo cliente' }}</h2>
      </header>

      <div class="cust-modal__body">
        <div class="cust-modal__row">
          <div class="cust-modal__field">
            <label for="c-type">Tipo *</label>
            <select id="c-type" v-model="form.type">
              <option value="individual">Persona fisica</option>
              <option value="business">Empresa</option>
            </select>
          </div>
          <div class="cust-modal__field cust-modal__field--grow">
            <label for="c-name">Nombre *</label>
            <input id="c-name" v-model="form.name" type="text" maxlength="200" placeholder="Nombre del cliente" />
          </div>
        </div>

        <div class="cust-modal__row">
          <div class="cust-modal__field">
            <label for="c-code">Codigo</label>
            <input id="c-code" v-model="form.code" type="text" maxlength="50" placeholder="Opcional" />
          </div>
          <div class="cust-modal__field cust-modal__field--grow">
            <label for="c-legal">Razon social</label>
            <input id="c-legal" v-model="form.legal_name" type="text" maxlength="200" placeholder="Opcional" />
          </div>
        </div>

        <div class="cust-modal__field">
          <label for="c-taxid">RFC / Tax ID</label>
          <input id="c-taxid" v-model="form.tax_id" type="text" maxlength="50" placeholder="Opcional" />
        </div>

        <div class="cust-modal__row">
          <div class="cust-modal__field">
            <label for="c-email">Email</label>
            <input id="c-email" v-model="form.email" type="email" maxlength="200" placeholder="correo@ejemplo.com" />
          </div>
          <div class="cust-modal__field">
            <label for="c-phone">Telefono</label>
            <input id="c-phone" v-model="form.phone" type="text" maxlength="30" placeholder="Opcional" />
          </div>
          <div class="cust-modal__field">
            <label for="c-mobile">Movil</label>
            <input id="c-mobile" v-model="form.mobile" type="text" maxlength="30" placeholder="Opcional" />
          </div>
        </div>

        <div class="cust-modal__field">
          <label for="c-addr">Direccion</label>
          <input id="c-addr" v-model="form.address_line" type="text" maxlength="300" placeholder="Calle y numero" />
        </div>

        <div class="cust-modal__row">
          <div class="cust-modal__field">
            <label for="c-city">Ciudad</label>
            <input id="c-city" v-model="form.city" type="text" maxlength="100" placeholder="Opcional" />
          </div>
          <div class="cust-modal__field">
            <label for="c-state">Estado</label>
            <input id="c-state" v-model="form.state" type="text" maxlength="100" placeholder="Opcional" />
          </div>
          <div class="cust-modal__field">
            <label for="c-zip">CP</label>
            <input id="c-zip" v-model="form.postal_code" type="text" maxlength="20" placeholder="Opcional" />
          </div>
          <div class="cust-modal__field">
            <label for="c-country">Pais</label>
            <input id="c-country" v-model="form.country_code" type="text" maxlength="2" placeholder="MX" />
          </div>
        </div>

        <div class="cust-modal__field">
          <label for="c-credit">Limite de credito</label>
          <input id="c-credit" v-model="form.credit_limit" type="text" inputmode="decimal" placeholder="0.00" />
        </div>

        <div class="cust-modal__row">
          <label class="cust-modal__check">
            <input v-model="form.is_active" type="checkbox" />
            <span>Activo</span>
          </label>
          <label class="cust-modal__check">
            <input v-model="form.is_blocked" type="checkbox" />
            <span>Bloqueado</span>
          </label>
        </div>

        <div v-if="form.is_blocked" class="cust-modal__field">
          <label for="c-blockreason">Motivo de bloqueo</label>
          <input id="c-blockreason" v-model="form.blocked_reason" type="text" maxlength="500" placeholder="Motivo" />
        </div>

        <div class="cust-modal__field">
          <label for="c-notes">Notas</label>
          <textarea id="c-notes" v-model="form.notes" rows="2" placeholder="Opcional..."></textarea>
        </div>

        <p v-if="errorMessage" class="cust-modal__error">{{ errorMessage }}</p>
      </div>

      <footer class="cust-modal__footer">
        <button type="button" class="cust-modal__btn cust-modal__btn--cancel" :disabled="store.saving" @click="emit('cancel')">
          Cancelar
        </button>
        <button type="button" class="cust-modal__btn cust-modal__btn--save" :disabled="!canSubmit" @click="onSubmit">
          {{ store.saving ? 'Guardando...' : (isEdit ? 'Guardar cambios' : 'Crear cliente') }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.cust-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: var(--pos-space-md);
}
.cust-modal {
  width: 100%;
  max-width: 620px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
  max-height: 92vh;
}
.cust-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}
.cust-modal__header h2 {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.2rem;
}
.cust-modal__body {
  padding: var(--pos-space-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.cust-modal__row {
  display: flex;
  gap: var(--pos-space-md);
}
.cust-modal__field {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  flex: 1;
  min-width: 0;
}
.cust-modal__field--grow {
  flex: 2;
}
.cust-modal__field label {
  font-size: 0.7rem;
  color: var(--color-text);
  opacity: 0.75;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.cust-modal__field input,
.cust-modal__field select,
.cust-modal__field textarea {
  padding: 0.6rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
  box-sizing: border-box;
  width: 100%;
}
.cust-modal__field textarea {
  resize: vertical;
}
.cust-modal__field input:focus,
.cust-modal__field select:focus,
.cust-modal__field textarea:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.cust-modal__check {
  display: flex;
  align-items: center;
  gap: var(--pos-space-xs);
  font-size: 0.9rem;
  color: var(--color-text);
  cursor: pointer;
  flex: 1;
}
.cust-modal__check input {
  width: auto;
}
.cust-modal__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.85rem;
  padding: var(--pos-space-sm);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: rgba(255, 0, 0, 0.06);
}
.cust-modal__footer {
  padding: var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
  display: flex;
  gap: var(--pos-space-md);
  justify-content: flex-end;
}
.cust-modal__btn {
  padding: 0.7rem 1.3rem;
  border-radius: var(--pos-radius-md);
  font-size: 0.95rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}
.cust-modal__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.cust-modal__btn--save {
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  border-color: var(--pos-accent);
}
.cust-modal__btn--save:hover:not(:disabled) {
  background: var(--pos-accent-hover);
}
</style>
