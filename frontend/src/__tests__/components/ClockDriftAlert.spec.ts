import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import ClockDriftAlert from '@/components/ClockDriftAlert.vue'

// Store integrity -> reactive() per patron del proyecto. El componente lee
// driftMs (para el texto) y los flags driftWarning / driftBlocked.
const integrity = reactive({
  driftMs: null as number | null,
  driftWarning: false,
  driftBlocked: false,
})

vi.mock('@/stores/integrity', () => ({
  useIntegrityStore: () => integrity,
}))

function reset(over: Partial<typeof integrity> = {}): void {
  integrity.driftMs = null
  integrity.driftWarning = false
  integrity.driftBlocked = false
  Object.assign(integrity, over)
}

function mountAlert(): VueWrapper {
  return mount(ClockDriftAlert)
}

beforeEach(() => {
  reset()
})

describe('ClockDriftAlert', () => {
  it('no muestra nada cuando no hay desfase relevante', () => {
    const wrapper = mountAlert()
    expect(wrapper.find('.drift-banner').exists()).toBe(false)
    expect(wrapper.find('.drift-modal').exists()).toBe(false)
  })

  it('muestra el banner de advertencia cuando driftWarning', () => {
    reset({ driftWarning: true, driftMs: 8 * 60000 })
    const wrapper = mountAlert()
    expect(wrapper.find('.drift-banner').exists()).toBe(true)
    expect(wrapper.find('.drift-modal').exists()).toBe(false)
  })

  it('muestra el modal de bloqueo cuando driftBlocked', () => {
    reset({ driftBlocked: true, driftMs: 35 * 60000 })
    const wrapper = mountAlert()
    expect(wrapper.find('.drift-modal').exists()).toBe(true)
    // El banner es v-else del modal: no debe renderizarse en bloqueo.
    expect(wrapper.find('.drift-banner').exists()).toBe(false)
  })

  it('describe el reloj adelantado cuando el desfase es positivo', () => {
    reset({ driftWarning: true, driftMs: 8 * 60000 })
    const wrapper = mountAlert()
    expect(wrapper.find('.drift-banner__msg').text()).toContain('8 minutos adelantado')
  })

  it('describe el reloj atrasado cuando el desfase es negativo', () => {
    reset({ driftWarning: true, driftMs: -6 * 60000 })
    const wrapper = mountAlert()
    expect(wrapper.find('.drift-banner__msg').text()).toContain('6 minutos atrasado')
  })

  it('usa el singular cuando el desfase es de un minuto', () => {
    reset({ driftWarning: true, driftMs: 60000 })
    const wrapper = mountAlert()
    const txt = wrapper.find('.drift-banner__msg').text()
    expect(txt).toContain('1 minuto adelantado')
    expect(txt).not.toContain('1 minutos')
  })
})
