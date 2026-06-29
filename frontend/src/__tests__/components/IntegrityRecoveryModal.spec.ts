import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import IntegrityRecoveryModal from '@/components/IntegrityRecoveryModal.vue'
import type { SnapshotProgress } from '@/sync/SnapshotService'

// El componente consume un COMPOSABLE (useIntegrityRecovery). Per patron del
// proyecto, los composables se mockean con ref() porque el template los
// desestructura y accede con .value explicito.
const exporting = ref(false)
const restoring = ref(false)
const errorMessage = ref<string | null>(null)
const exported = ref(false)
const restored = ref(false)
const restoreProgress = ref<SnapshotProgress | null>(null)
const exportPending = vi.fn()
const restore = vi.fn(async () => true)

vi.mock('@/composables/useIntegrityRecovery', () => ({
  useIntegrityRecovery: () => ({
    exporting,
    restoring,
    errorMessage,
    exported,
    restored,
    restoreProgress,
    exportPending,
    restore,
  }),
}))

function resetState(): void {
  exporting.value = false
  restoring.value = false
  errorMessage.value = null
  exported.value = false
  restored.value = false
  restoreProgress.value = null
  exportPending.mockReset()
  restore.mockReset()
  restore.mockResolvedValue(true)
}

function mountModal(): VueWrapper {
  return mount(IntegrityRecoveryModal)
}

beforeEach(() => {
  resetState()
})

describe('IntegrityRecoveryModal', () => {
  it('renderiza el modal de datos corruptos', () => {
    const wrapper = mountModal()
    expect(wrapper.find('.intg-modal').exists()).toBe(true)
    expect(wrapper.text()).toContain('Datos locales corruptos')
  })

  it('llama exportPending al pulsar Exportar pendientes', async () => {
    const wrapper = mountModal()
    await wrapper.find('.intg-modal__btn').trigger('click')
    expect(exportPending).toHaveBeenCalledTimes(1)
  })

  it('llama restore al pulsar Restaurar', async () => {
    const wrapper = mountModal()
    await wrapper.find('.intg-modal__btn--danger').trigger('click')
    expect(restore).toHaveBeenCalledTimes(1)
  })

  it('emite restored cuando la restauracion tiene exito', async () => {
    restore.mockResolvedValue(true)
    const wrapper = mountModal()
    await wrapper.find('.intg-modal__btn--danger').trigger('click')
    await Promise.resolve()
    await Promise.resolve()
    expect(wrapper.emitted('restored')).toHaveLength(1)
  })

  it('no emite restored cuando la restauracion falla', async () => {
    restore.mockResolvedValue(false)
    const wrapper = mountModal()
    await wrapper.find('.intg-modal__btn--danger').trigger('click')
    await Promise.resolve()
    await Promise.resolve()
    expect(wrapper.emitted('restored')).toBeUndefined()
  })

  it('muestra el mensaje de error cuando hay errorMessage', async () => {
    const wrapper = mountModal()
    errorMessage.value = 'Fallo la operacion'
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.intg-modal__error').text()).toBe('Fallo la operacion')
  })

  it('muestra la nota de exportacion cuando exported es true', async () => {
    const wrapper = mountModal()
    exported.value = true
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.intg-modal__note--ok').exists()).toBe(true)
  })

  it('muestra el progreso de repoblado traducido', async () => {
    const wrapper = mountModal()
    restoreProgress.value = { entity: 'products', phase: 'done', count: 42 }
    await wrapper.vm.$nextTick()
    const note = wrapper.find('.intg-modal__note')
    expect(note.text()).toContain('productos')
    expect(note.text()).toContain('42')
  })

  it('deshabilita ambos botones mientras restaura', async () => {
    const wrapper = mountModal()
    restoring.value = true
    await wrapper.vm.$nextTick()
    const buttons = wrapper.findAll('.intg-modal__btn')
    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[1].attributes('disabled')).toBeDefined()
  })

  it('cambia el label del boton restaurar mientras restaura', async () => {
    const wrapper = mountModal()
    restoring.value = true
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.intg-modal__btn--danger').text()).toContain('Restaurando')
  })
})
