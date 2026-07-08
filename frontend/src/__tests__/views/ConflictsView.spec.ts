import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import ConflictsView from '@/views/ConflictsView.vue'
import type { ConflictLocal } from '@/db/schema'

// Composable useConflicts -> refs (se desestructuran, acceso .value).
const load = vi.fn()
const resolveManual = vi.fn()
const actionsFor = vi.fn(() => [
  { label: 'Mantener mi version', resolution: 'use_client', variant: 'default' },
  { label: 'Aceptar del servidor', resolution: 'use_server', variant: 'primary' },
])
const items = ref<ConflictLocal[]>([])
const loading = ref(false)
const errorMessage = ref<string | null>(null)
const resolvingUuid = ref<string | null>(null)
const canResolve = ref(true)
const isEmpty = ref(false)

vi.mock('@/composables/useConflicts', () => ({
  useConflicts: () => ({
    items, loading, errorMessage, resolvingUuid, canResolve, isEmpty,
    load, resolveManual, actionsFor,
  }),
}))

function makeConflict(over: Partial<ConflictLocal> = {}): ConflictLocal {
  return {
    uuid: 'cf1',
    entityType: 'sale',
    entityUuid: 'sale-123',
    clientUuid: 'cli-1',
    reason: 'STOCK_NEGATIVE',
    clientPayload: {},
    serverData: {},
    resolution: null,
    auto: false,
    requireRole: 'manager',
    detectedAt: '2026-01-01T10:00:00Z',
    resolvedAt: null,
    ...over,
  } as unknown as ConflictLocal
}

function resetState(): void {
  load.mockReset()
  resolveManual.mockReset()
  actionsFor.mockClear()
  items.value = []
  loading.value = false
  errorMessage.value = null
  resolvingUuid.value = null
  canResolve.value = true
  isEmpty.value = false
}

function mountView(): VueWrapper {
  return mount(ConflictsView, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resetState()
})

describe('ConflictsView', () => {
  it('carga los conflictos al montar', () => {
    mountView()
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('muestra el estado de carga', () => {
    loading.value = true
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cargando conflictos')
  })

  it('muestra el estado vacio cuando no hay conflictos', () => {
    isEmpty.value = true
    const wrapper = mountView()
    expect(wrapper.text()).toContain('No hay conflictos pendientes')
  })

  it('renderiza una card por conflicto con etiquetas traducidas', () => {
    items.value = [makeConflict()]
    const wrapper = mountView()
    expect(wrapper.findAll('.conf-card')).toHaveLength(1)
    const txt = wrapper.text()
    expect(txt).toContain('Venta')             // entityLabel('sale')
    expect(txt).toContain('Stock insuficiente') // reasonLabel('STOCK_NEGATIVE')
    expect(txt).toContain('sale-123')
  })

  it('usa el valor crudo cuando la razon no tiene etiqueta', () => {
    items.value = [makeConflict({ reason: 'WEIRD_CASE' as unknown as ConflictLocal['reason'] })]
    const wrapper = mountView()
    expect(wrapper.text()).toContain('WEIRD_CASE')
  })

  it('muestra el conteo de conflictos en el subtitulo', () => {
    items.value = [makeConflict(), makeConflict({ uuid: 'cf2', entityUuid: 'sale-456' })]
    const wrapper = mountView()
    expect(wrapper.find('.conf-view__subtitle').text()).toContain('2')
  })

  it('muestra el banner de error', () => {
    errorMessage.value = 'No se pudieron cargar los conflictos'
    const wrapper = mountView()
    expect(wrapper.find('.conf-view__error').text()).toBe('No se pudieron cargar los conflictos')
  })

  it('renderiza los botones de accion cuando se puede resolver', () => {
    items.value = [makeConflict()]
    canResolve.value = true
    const wrapper = mountView()
    expect(wrapper.findAll('.conf-card__btn')).toHaveLength(2)
  })

  it('resuelve el conflicto al pulsar una accion', async () => {
    items.value = [makeConflict()]
    canResolve.value = true
    const wrapper = mountView()
    await wrapper.find('.conf-card__btn').trigger('click')
    expect(resolveManual).toHaveBeenCalledWith('cf1', 'use_client')
  })

  it('deshabilita las acciones del conflicto que se esta resolviendo', () => {
    items.value = [makeConflict()]
    canResolve.value = true
    resolvingUuid.value = 'cf1'
    const wrapper = mountView()
    expect(wrapper.find('.conf-card__btn').attributes('disabled')).toBeDefined()
  })

  it('muestra el aviso de solo lectura y oculta acciones sin permiso', () => {
    items.value = [makeConflict()]
    canResolve.value = false
    isEmpty.value = false
    const wrapper = mountView()
    expect(wrapper.find('.conf-view__notice').exists()).toBe(true)
    expect(wrapper.find('.conf-card__btn').exists()).toBe(false)
  })

  it('no muestra el aviso de solo lectura cuando no hay conflictos', () => {
    canResolve.value = false
    isEmpty.value = true
    const wrapper = mountView()
    expect(wrapper.find('.conf-view__notice').exists()).toBe(false)
  })
})
