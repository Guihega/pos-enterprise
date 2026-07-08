import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive, nextTick } from 'vue'
import LoginView from '@/views/LoginView.vue'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

// Store auth -> reactive() per patron. Solo se usa login() en esta vista.
const authStore = reactive({
  login: vi.fn(async () => {}),
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authStore,
}))

const LAST_TENANT_KEY = 'pos:auth:last_tenant'

function mountView(): VueWrapper {
  return mount(LoginView)
}

async function fillValidForm(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('#lv-tenant').setValue('demo')
  await wrapper.find('#lv-email').setValue('admin@demo.local')
  await wrapper.find('#lv-password').setValue('password123')
}

beforeEach(() => {
  vi.clearAllMocks()
  push.mockClear()
  authStore.login = vi.fn(async () => {})
  localStorage.clear()
  // Aislamos el fallback de tenant: sin env por defecto.
  vi.stubEnv('VITE_DEV_TENANT', '')
})

describe('LoginView', () => {
  it('renderiza el formulario de inicio de sesion', () => {
    const wrapper = mountView()
    expect(wrapper.find('.lv-form').exists()).toBe(true)
    expect(wrapper.find('#lv-tenant').exists()).toBe(true)
    expect(wrapper.find('#lv-email').exists()).toBe(true)
    expect(wrapper.find('#lv-password').exists()).toBe(true)
  })

  it('precarga el tenant recordado desde localStorage', async () => {
    localStorage.setItem(LAST_TENANT_KEY, 'acme')
    const wrapper = mountView()
    await nextTick()
    expect((wrapper.find('#lv-tenant').element as HTMLInputElement).value).toBe('acme')
  })

  it('usa VITE_DEV_TENANT como fallback cuando no hay recordado', async () => {
    vi.stubEnv('VITE_DEV_TENANT', 'demo')
    const wrapper = mountView()
    await nextTick()
    expect((wrapper.find('#lv-tenant').element as HTMLInputElement).value).toBe('demo')
  })

  it('muestra error de correo invalido tras tocar el campo', async () => {
    const wrapper = mountView()
    await wrapper.find('#lv-email').setValue('no-es-correo')
    await wrapper.find('#lv-email').trigger('blur')
    expect(wrapper.text()).toContain('Ingresa un correo valido')
  })

  it('no llama login cuando el formulario es invalido', async () => {
    const wrapper = mountView()
    await wrapper.find('.lv-form').trigger('submit')
    await flushPromises()
    expect(authStore.login).not.toHaveBeenCalled()
  })

  it('llama login con email, password y tenant al enviar un formulario valido', async () => {
    const wrapper = mountView()
    await fillValidForm(wrapper)
    await wrapper.find('.lv-form').trigger('submit')
    await flushPromises()
    expect(authStore.login).toHaveBeenCalledWith('admin@demo.local', 'password123', 'demo')
  })

  it('persiste el tenant y redirige al POS tras login exitoso', async () => {
    const wrapper = mountView()
    await fillValidForm(wrapper)
    await wrapper.find('.lv-form').trigger('submit')
    await flushPromises()
    expect(localStorage.getItem(LAST_TENANT_KEY)).toBe('demo')
    expect(push).toHaveBeenCalledWith({ name: 'pos' })
  })

  it('muestra el mensaje del backend cuando login falla', async () => {
    authStore.login = vi.fn(async () => {
      throw { error: { message: 'Credenciales invalidas.' } }
    })
    const wrapper = mountView()
    await fillValidForm(wrapper)
    await wrapper.find('.lv-form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('.lv-error-banner').text()).toContain('Credenciales invalidas')
    expect(push).not.toHaveBeenCalled()
  })

  it('muestra un mensaje generico ante un error sin estructura', async () => {
    authStore.login = vi.fn(async () => {
      throw new Error('boom')
    })
    const wrapper = mountView()
    await fillValidForm(wrapper)
    await wrapper.find('.lv-form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('.lv-error-banner').text()).toContain('No se pudo iniciar sesion')
  })

  it('alterna la visibilidad de la contrasena', async () => {
    const wrapper = mountView()
    const input = wrapper.find('#lv-password')
    expect(input.attributes('type')).toBe('password')
    await wrapper.find('.lv-toggle-pass').trigger('click')
    expect(input.attributes('type')).toBe('text')
  })
})
