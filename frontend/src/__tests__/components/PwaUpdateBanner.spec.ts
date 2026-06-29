import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import PwaUpdateBanner from '@/components/PwaUpdateBanner.vue'

// Store pwa -> reactive() per patron. El template lee needRefresh / updating
// y llama applyUpdate / dismiss.
const pwa = reactive({
  needRefresh: false,
  updating: false,
  applyUpdate: vi.fn(),
  dismiss: vi.fn(),
})

vi.mock('@/stores/pwa', () => ({
  usePwaStore: () => pwa,
}))

function reset(over: Partial<typeof pwa> = {}): void {
  pwa.needRefresh = false
  pwa.updating = false
  pwa.applyUpdate = vi.fn()
  pwa.dismiss = vi.fn()
  Object.assign(pwa, over)
}

function mountBanner(): VueWrapper {
  return mount(PwaUpdateBanner)
}

beforeEach(() => {
  reset()
})

describe('PwaUpdateBanner', () => {
  it('permanece oculto cuando no hay actualizacion pendiente', () => {
    const wrapper = mountBanner()
    expect(wrapper.find('.pwa-banner').exists()).toBe(false)
  })

  it('muestra el banner cuando hay una version nueva', () => {
    reset({ needRefresh: true })
    const wrapper = mountBanner()
    expect(wrapper.find('.pwa-banner--update').exists()).toBe(true)
    expect(wrapper.text()).toContain('nueva version')
  })

  it('llama applyUpdate al pulsar Actualizar ahora', async () => {
    reset({ needRefresh: true })
    const wrapper = mountBanner()
    await wrapper.find('.pwa-banner__btn--primary').trigger('click')
    expect(pwa.applyUpdate).toHaveBeenCalledTimes(1)
  })

  it('llama dismiss al pulsar Despues', async () => {
    reset({ needRefresh: true })
    const wrapper = mountBanner()
    // El segundo boton (sin --primary) es "Despues".
    const buttons = wrapper.findAll('.pwa-banner__btn')
    const despues = buttons.find((b) => b.text() === 'Despues')
    expect(despues).toBeTruthy()
    await despues!.trigger('click')
    expect(pwa.dismiss).toHaveBeenCalledTimes(1)
  })

  it('deshabilita los botones y muestra Actualizando mientras actualiza', () => {
    reset({ needRefresh: true, updating: true })
    const wrapper = mountBanner()
    const primary = wrapper.find('.pwa-banner__btn--primary')
    expect(primary.text()).toContain('Actualizando')
    expect(primary.attributes('disabled')).toBeDefined()
  })
})
