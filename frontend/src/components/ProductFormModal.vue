<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useProductsStore } from '@/stores/products'
import { useCatalogOptions } from '@/composables/useCatalogOptions'
import type { Product, StoreProductRequest, UpdateProductRequest } from '@/lib/api/generated'

const props = defineProps<{ product?: Product | null }>()
const emit = defineEmits<{ saved: [product: Product]; cancel: [] }>()

const store = useProductsStore()
const options = useCatalogOptions()

const isEdit = computed(() => props.product != null)
const errorMessage = ref<string | null>(null)

// Estado del formulario. Para inputs monetarios usamos string + inputmode
// decimal (v-model.number lanza TypeError silencioso al parsear).
const form = reactive({
  sku: '',
  name: '',
  description: '',
  price: '',
  cost: '',
  unit_uuid: '',
  category_uuid: '',
  brand_uuid: '',
  tax_uuid: '',
  status: 'active' as 'draft' | 'active' | 'archived',
})

onMounted(async () => {
  await options.init()
  if (props.product) {
    const p = props.product
    form.sku = p.sku
    form.name = p.name
    form.description = p.description ?? ''
    form.price = String(p.pricing.price)
    form.cost = p.pricing.cost != null ? String(p.pricing.cost) : ''
    form.unit_uuid = p.unit?.uuid ?? ''
    form.category_uuid = p.category?.uuid ?? ''
    form.brand_uuid = p.brand?.uuid ?? ''
    form.tax_uuid = p.tax?.uuid ?? ''
    form.status = p.status
  }
})

const canSubmit = computed(
  () =>
    form.sku.trim() !== '' &&
    form.name.trim() !== '' &&
    form.unit_uuid !== '' &&
    form.price.trim() !== '' &&
    !store.saving,
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

  const price = parseMoney(form.price)
  if (price === null || price < 0) {
    errorMessage.value = 'El precio debe ser un numero valido mayor o igual a 0.'
    return
  }
  const cost = parseMoney(form.cost)
  if (form.cost.trim() !== '' && (cost === null || cost < 0)) {
    errorMessage.value = 'El costo debe ser un numero valido mayor o igual a 0.'
    return
  }

  const payload: StoreProductRequest = {
    sku: form.sku.trim(),
    name: form.name.trim(),
    description: form.description.trim() || null,
    price,
    cost,
    unit_uuid: form.unit_uuid,
    category_uuid: form.category_uuid || null,
    brand_uuid: form.brand_uuid || null,
    tax_uuid: form.tax_uuid || null,
    status: form.status,
  }

  const result = props.product
    ? await store.update(props.product.uuid, payload as UpdateProductRequest)
    : await store.create(payload)

  if (result.ok && result.product) {
    emit('saved', result.product)
  } else {
    errorMessage.value = result.errorMessage ?? 'No se pudo guardar el producto.'
  }
}
</script>

<template>
  <div class="prod-modal__backdrop" @click.self="emit('cancel')">
    <div class="prod-modal" role="dialog" aria-modal="true">
      <header class="prod-modal__header">
        <h2>{{ isEdit ? 'Editar producto' : 'Nuevo producto' }}</h2>
      </header>

      <div class="prod-modal__body">
        <div class="prod-modal__row">
          <div class="prod-modal__field">
            <label for="p-sku">SKU *</label>
            <input id="p-sku" v-model="form.sku" type="text" maxlength="60" placeholder="SKU-001" />
          </div>
          <div class="prod-modal__field prod-modal__field--grow">
            <label for="p-name">Nombre *</label>
            <input id="p-name" v-model="form.name" type="text" maxlength="300" placeholder="Nombre del producto" />
          </div>
        </div>

        <div class="prod-modal__field">
          <label for="p-desc">Descripcion</label>
          <textarea id="p-desc" v-model="form.description" rows="2" maxlength="2000" placeholder="Opcional..."></textarea>
        </div>

        <div class="prod-modal__row">
          <div class="prod-modal__field">
            <label for="p-price">Precio *</label>
            <input id="p-price" v-model="form.price" type="text" inputmode="decimal" placeholder="0.00" />
          </div>
          <div class="prod-modal__field">
            <label for="p-cost">Costo</label>
            <input id="p-cost" v-model="form.cost" type="text" inputmode="decimal" placeholder="0.00" />
          </div>
        </div>

        <div class="prod-modal__row">
          <div class="prod-modal__field">
            <label for="p-unit">Unidad *</label>
            <select id="p-unit" v-model="form.unit_uuid">
              <option value="" disabled>Selecciona...</option>
              <option v-for="u in options.units.value" :key="u.uuid" :value="u.uuid">
                {{ u.name }} ({{ u.code }})
              </option>
            </select>
          </div>
          <div class="prod-modal__field">
            <label for="p-tax">Impuesto</label>
            <select id="p-tax" v-model="form.tax_uuid">
              <option value="">Sin impuesto</option>
              <option v-for="t in options.taxes.value" :key="t.uuid" :value="t.uuid">
                {{ t.name }} ({{ t.rate_percent }}%)
              </option>
            </select>
          </div>
        </div>

        <div class="prod-modal__row">
          <div class="prod-modal__field">
            <label for="p-cat">Categoria</label>
            <select id="p-cat" v-model="form.category_uuid">
              <option value="">Sin categoria</option>
              <option v-for="c in options.categories.value" :key="c.uuid" :value="c.uuid">
                {{ c.name }}
              </option>
            </select>
          </div>
          <div class="prod-modal__field">
            <label for="p-brand">Marca</label>
            <select id="p-brand" v-model="form.brand_uuid">
              <option value="">Sin marca</option>
              <option v-for="b in options.brands.value" :key="b.uuid" :value="b.uuid">
                {{ b.name }}
              </option>
            </select>
          </div>
        </div>

        <div class="prod-modal__field">
          <label for="p-status">Estado</label>
          <select id="p-status" v-model="form.status">
            <option value="draft">Borrador</option>
            <option value="active">Activo</option>
            <option value="archived">Archivado</option>
          </select>
        </div>

        <p v-if="options.errorMessage.value" class="prod-modal__error">{{ options.errorMessage.value }}</p>
        <p v-if="errorMessage" class="prod-modal__error">{{ errorMessage }}</p>
      </div>

      <footer class="prod-modal__footer">
        <button type="button" class="prod-modal__btn prod-modal__btn--cancel" :disabled="store.saving" @click="emit('cancel')">
          Cancelar
        </button>
        <button type="button" class="prod-modal__btn prod-modal__btn--save" :disabled="!canSubmit" @click="onSubmit">
          {{ store.saving ? 'Guardando...' : (isEdit ? 'Guardar cambios' : 'Crear producto') }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.prod-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: var(--pos-space-md);
}
.prod-modal {
  width: 100%;
  max-width: 560px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
  max-height: 92vh;
}
.prod-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}
.prod-modal__header h2 {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.2rem;
}
.prod-modal__body {
  padding: var(--pos-space-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.prod-modal__row {
  display: flex;
  gap: var(--pos-space-md);
}
.prod-modal__field {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  flex: 1;
  min-width: 0;
}
.prod-modal__field--grow {
  flex: 2;
}
.prod-modal__field label {
  font-size: 0.7rem;
  color: var(--color-text);
  opacity: 0.75;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.prod-modal__field input,
.prod-modal__field select,
.prod-modal__field textarea {
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
.prod-modal__field textarea {
  resize: vertical;
}
.prod-modal__field input:focus,
.prod-modal__field select:focus,
.prod-modal__field textarea:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.prod-modal__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.85rem;
  padding: var(--pos-space-sm);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: rgba(255, 0, 0, 0.06);
}
.prod-modal__footer {
  padding: var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
  display: flex;
  gap: var(--pos-space-md);
  justify-content: flex-end;
}
.prod-modal__btn {
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
.prod-modal__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.prod-modal__btn--save {
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  border-color: var(--pos-accent);
}
.prod-modal__btn--save:hover:not(:disabled) {
  background: var(--pos-accent-hover);
}
</style>
