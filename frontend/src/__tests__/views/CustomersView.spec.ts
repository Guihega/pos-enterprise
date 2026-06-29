import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref, reactive } from 'vue'
import CustomersView from '@/views/CustomersView.vue'
import type { Customer } from '@/lib/api/generated'

// Composable useCustomers -> ref() (acceso .value en el template).
const init = vi.fn()
const loadMore = vi.fn()
const retry = vi.fn()
const searchTerm = ref('')
const items = ref<Customer[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const errorMessage = ref<string | null>(null)
const hasMore = ref(false)
const total = ref(0)

vi.mock('@/composables/useCustomers', () => ({
  useCustomers: () => ({
    init, searchTerm, items, loading, loadingMore,
    errorMessage, hasMore, total, loadMore, retry,
  }),
}))

// Store customers -> reactive() (acceso sin .value).
const store = reactive({
  deleting: false,
  remove: vi.fn(async () => ({ ok: true })),
})

vi.mock('@/stores/customers', () => ({
  useCustomersStore: () => store,
}))

function makeCustomer(over: Partial<Customer> = {}): Customer {
  return {
    uuid: 'c1',
    code: 'CLI-1',
    type: 'individual',
    name: 'Ana Lopez',
    contact: { email: 'ana@correo.com', phone: null, mobile: null },
    credit: { limit: 0, balance: 0, available: 0 },
    flags: { is_active: true, is_blocked: false, blocked_reason: null },
    ...over,
  } as unknown as Customer
}

function resetState(): void {
  init.mockReset()
  loadMore.mockReset()
  retry.mockReset()
  searchTerm.value = ''
  items.value = []
  loading.value = false
  loadingMore.value = false
  errorMessage.value = null
  hasMore.value = false
  total.value = 0
  store.deleting = false
  store.remove = vi.fn(async () => ({ ok: true }))
}

function mountView(): VueWrapper {
  return mount(CustomersView, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        CustomerFormModal: {
          template: '<div class="stub-customer-modal"></div>',
          props: ['customer'],
          emits: ['saved', 'cancel'],
        },
      },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resetState()
})

describe('CustomersView', () => {
  it('inicializa el listado al montar', () => {
    mountView()
    expect(init).toHaveBeenCalledTimes(1)
  })

  it('muestra el estado de carga', () => {
    loading.value = true
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cargando clientes')
    expect(wrapper.find('.cust-table').exists()).toBe(false)
  })

  it('muestra el estado vacio cuando no hay clientes', () => {
    items.value = []
    const wrapper = mountView()
    expect(wrapper.text()).toContain('No hay clientes')
  })

  it('muestra el error y permite reintentar', async () => {
    errorMessage.value = 'Fallo de red'
    const wrapper = mountView()
    expect(wrapper.find('.cust-view__error').text()).toBe('Fallo de red')
    await wrapper.find('.cust-view__state button').trigger('click')
    expect(retry).toHaveBeenCalledTimes(1)
  })

  it('renderiza una fila por cliente', () => {
    items.value = [makeCustomer(), makeCustomer({ uuid: 'c2', name: 'Beto', code: 'CLI-2' })]
    const wrapper = mountView()
    expect(wrapper.findAll('.cust-table tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('Ana Lopez')
    expect(wrapper.text()).toContain('Beto')
  })

  it('traduce el tipo de cliente', () => {
    items.value = [
      makeCustomer({ uuid: 'c1', type: 'business', name: 'ACME SA' }),
      makeCustomer({ uuid: 'c2', type: 'individual', name: 'Ana' }),
    ]
    const wrapper = mountView()
    const txt = wrapper.text()
    expect(txt).toContain('Empresa')
    expect(txt).toContain('Persona')
  })

  it('muestra el email de contacto cuando existe', () => {
    items.value = [makeCustomer({ contact: { email: 'x@y.com', phone: '555', mobile: null } })]
    const wrapper = mountView()
    expect(wrapper.find('.cust-table__contact').text()).toContain('x@y.com')
  })

  it('muestra el estado bloqueado con prioridad', () => {
    items.value = [makeCustomer({ flags: { is_active: true, is_blocked: true, blocked_reason: 'mora' } })]
    const wrapper = mountView()
    expect(wrapper.find('.cust-table__status--blocked').exists()).toBe(true)
  })

  it('muestra el total de clientes en el subtitulo', () => {
    total.value = 5
    const wrapper = mountView()
    expect(wrapper.find('.cust-view__subtitle').text()).toContain('5')
  })

  it('abre el modal al pulsar Nuevo cliente', async () => {
    const wrapper = mountView()
    expect(wrapper.find('.stub-customer-modal').exists()).toBe(false)
    await wrapper.find('.cust-view__new').trigger('click')
    expect(wrapper.find('.stub-customer-modal').exists()).toBe(true)
  })

  it('abre el modal al pulsar Editar', async () => {
    items.value = [makeCustomer()]
    const wrapper = mountView()
    await wrapper.find('.cust-table__btn').trigger('click')
    expect(wrapper.find('.stub-customer-modal').exists()).toBe(true)
  })

  it('pide confirmacion antes de eliminar', async () => {
    items.value = [makeCustomer()]
    const wrapper = mountView()
    await wrapper.find('.cust-table__btn--danger').trigger('click')
    expect(wrapper.text()).toContain('Eliminar?')
  })

  it('elimina el cliente al confirmar y muestra feedback', async () => {
    items.value = [makeCustomer()]
    const wrapper = mountView()
    await wrapper.find('.cust-table__btn--danger').trigger('click')
    const yes = wrapper.findAll('.cust-table__btn--danger').find((b) => b.text() === 'Si')
    expect(yes).toBeTruthy()
    await yes!.trigger('click')
    await flushPromises()
    expect(store.remove).toHaveBeenCalledWith('c1')
    expect(wrapper.find('.cust-view__feedback').text()).toContain('eliminado')
  })

  it('muestra el error de borrado cuando remove falla', async () => {
    store.remove = vi.fn(async () => ({ ok: false, errorMessage: 'Cliente con saldo deudor' }))
    items.value = [makeCustomer()]
    const wrapper = mountView()
    await wrapper.find('.cust-table__btn--danger').trigger('click')
    const yes = wrapper.findAll('.cust-table__btn--danger').find((b) => b.text() === 'Si')
    await yes!.trigger('click')
    await flushPromises()
    expect(wrapper.find('.cust-view__error').text()).toBe('Cliente con saldo deudor')
  })

  it('muestra Cargar mas y pagina cuando hasMore', async () => {
    items.value = [makeCustomer()]
    hasMore.value = true
    const wrapper = mountView()
    await wrapper.find('.cust-view__more button').trigger('click')
    expect(loadMore).toHaveBeenCalledTimes(1)
  })
})
