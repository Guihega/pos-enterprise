import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import PosHeader from '@/components/PosHeader.vue'
import type { CashSession } from '@/lib/api/generated'

// useRouter se mockea para capturar push (onLogout hace router.push).
const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  RouterLink: { template: '<a><slot /></a>' },
}))

// Stores de Pinia -> reactive() per patron del proyecto (acceso sin .value).
const authStore = reactive({
  user: null as { name: string } | null,
  logout: vi.fn(async () => {}),
})

const cashStore = reactive({
  currentSession: null as CashSession | null,
  clear: vi.fn(),
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authStore,
}))
vi.mock('@/stores/cashSession', () => ({
  useCashSessionStore: () => cashStore,
}))

function makeSession(): CashSession {
  return {
    uuid: 'cs1',
    status: 'open',
    opened_at: '2026-01-01T08:00:00Z',
    opening: { amount: 1000 },
    register: { uuid: 'r1', code: 'CAJA-01', name: 'Caja 1' },
  } as unknown as CashSession
}

function resetStores(): void {
  authStore.user = null
  authStore.logout = vi.fn(async () => {})
  cashStore.currentSession = null
  cashStore.clear = vi.fn()
}

function mountHeader(): VueWrapper {
  return mount(PosHeader, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  push.mockClear()
  resetStores()
})

describe('PosHeader', () => {
  it('oculta los controles de caja cuando no hay sesion activa', () => {
    const wrapper = mountHeader()
    expect(wrapper.find('.pos-header__cash').exists()).toBe(false)
    expect(wrapper.find('.pos-header__corte-x').exists()).toBe(false)
    expect(wrapper.find('.pos-header__close-cash').exists()).toBe(false)
  })

  it('muestra los controles de caja cuando hay sesion activa', () => {
    cashStore.currentSession = makeSession()
    const wrapper = mountHeader()
    expect(wrapper.find('.pos-header__cash').exists()).toBe(true)
    expect(wrapper.find('.pos-header__corte-x').exists()).toBe(true)
    expect(wrapper.find('.pos-header__close-cash').exists()).toBe(true)
  })

  it('muestra el codigo del registro en la etiqueta de caja', () => {
    cashStore.currentSession = makeSession()
    const wrapper = mountHeader()
    expect(wrapper.find('.pos-header__cash').text()).toContain('CAJA-01')
  })

  it('emite corte-x al pulsar Corte X', async () => {
    cashStore.currentSession = makeSession()
    const wrapper = mountHeader()
    await wrapper.find('.pos-header__corte-x').trigger('click')
    expect(wrapper.emitted('corte-x')).toHaveLength(1)
  })

  it('emite close-cash al pulsar Cerrar caja', async () => {
    cashStore.currentSession = makeSession()
    const wrapper = mountHeader()
    await wrapper.find('.pos-header__close-cash').trigger('click')
    expect(wrapper.emitted('close-cash')).toHaveLength(1)
  })

  it('muestra el nombre del usuario autenticado', () => {
    authStore.user = { name: 'Ana Lopez' }
    const wrapper = mountHeader()
    expect(wrapper.find('.pos-header__user-name').text()).toBe('Ana Lopez')
  })

  it('oculta el nombre cuando no hay usuario', () => {
    authStore.user = null
    const wrapper = mountHeader()
    expect(wrapper.find('.pos-header__user-name').exists()).toBe(false)
  })

  it('al pulsar Salir hace logout, limpia la caja y redirige a login', async () => {
    const wrapper = mountHeader()
    await wrapper.find('.pos-header__logout').trigger('click')
    // onLogout es async: esperamos a que se resuelvan las promesas.
    await Promise.resolve()
    await Promise.resolve()
    expect(authStore.logout).toHaveBeenCalledTimes(1)
    expect(cashStore.clear).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'login' })
  })
})
