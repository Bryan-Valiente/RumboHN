import { describe, expect, it } from 'vitest'
import { emptyFinancialState } from '../src/finance'
import { decryptWorkspace, encryptWorkspace, inspectWorkspaceStorage, parseWorkspace, readWorkspace, saveWorkspace, trialDaysRemaining, trialExpired, WORKSPACE_STORAGE_KEY, type PersistedWorkspace } from '../src/persistence'

class MemoryStorage {
  private values = new Map<string, string>()
  getItem(key: string) { return this.values.get(key) ?? null }
  setItem(key: string, value: string) { this.values.set(key, value) }
  removeItem(key: string) { this.values.delete(key) }
}

const workspace: PersistedWorkspace = {
  version: 1,
  profile: { name: 'Bryan', preferredGoals: ['Ahorrar para una meta'], incomeFrequency: 'Mensual', incomeAmount: 12000, createdAt: 1_000, trialEndsAt: 1_000 + 7 * 86400000, onboardingCompleted: true },
  financial: {
    ...emptyFinancialState,
    movements: [{ id: 22, name: 'Café', category: 'Gastos hormiga', amount: 35, kind: 'expense', date: '2026-11-18', color: 'gold', timestamp: '2026-11-18T15:00:00.000Z', quickExpense: true }],
    templates: [{ id: 3, name: 'Internet', kind: 'expense', category: 'Internet', defaultAmount: 700, recurrence: 'monthly' }],
  },
  subscription: { planId: 'camino', activatedAt: 2_000, status: 'active' },
}

describe('workspace persistence', () => {
  it('restores personal movements, templates and subscription from browser storage', () => {
    const storage = new MemoryStorage()
    expect(saveWorkspace(workspace, storage)).toEqual({ value: true, error: null })
    const restored = readWorkspace(storage)

    expect(restored.error).toBeNull()
    expect(restored.value?.profile.name).toBe('Bryan')
    expect(restored.value?.financial.movements[0].quickExpense).toBe(true)
    expect(restored.value?.financial.templates?.[0].name).toBe('Internet')
    expect(restored.value?.subscription?.planId).toBe('camino')
    expect(storage.getItem(WORKSPACE_STORAGE_KEY)).toContain('Café')
  })

  it('normalizes malformed storage without inventing a personal transaction', () => {
    const parsed = parseWorkspace(JSON.stringify({ profile: workspace.profile, financial: { movements: 'bad' } }))

    expect(parsed?.financial.movements).toEqual([])
    expect(parsed?.financial.goals).toEqual([])
    expect(parsed?.subscription).toBeNull()
  })

  it('restores recurring reminders and notification preferences', () => {
    const storage = new MemoryStorage()
    const withReminder: PersistedWorkspace = {
      ...workspace,
      financial: {
        ...workspace.financial,
        reminders: [{ id: 9, name: 'Internet', category: 'Internet', expectedAmount: 700, dueDate: '2026-11-20', recurrence: 'monthly', reminderDaysBefore: 3, completed: false, notes: 'Demo' }],
        notificationPreferences: { ...workspace.financial.notificationPreferences, evening: false, eveningTime: '21:00' },
      },
    }

    saveWorkspace(withReminder, storage)
    const restored = readWorkspace(storage).value

    expect(restored?.financial.reminders[0].name).toBe('Internet')
    expect(restored?.financial.reminders[0].recurrence).toBe('monthly')
    expect(restored?.financial.notificationPreferences.evening).toBe(false)
    expect(restored?.financial.notificationPreferences.eveningTime).toBe('21:00')
  })

  it('shows seven days at creation and zero after the exact expiry moment', () => {
    expect(trialDaysRemaining(workspace.profile, workspace.profile.createdAt)).toBe(7)
    expect(trialDaysRemaining(workspace.profile, workspace.profile.trialEndsAt)).toBe(0)
    expect(trialExpired(workspace.profile, workspace.profile.trialEndsAt - 1)).toBe(false)
    expect(trialExpired(workspace.profile, workspace.profile.trialEndsAt)).toBe(true)
  })

  it('encrypts the workspace and rejects an incorrect password', async () => {
    const storage = new MemoryStorage()
    const envelope = await encryptWorkspace({ ...workspace, version: 2 }, 'rumbo-seguro-123')
    storage.setItem(WORKSPACE_STORAGE_KEY, JSON.stringify(envelope))
    expect(storage.getItem(WORKSPACE_STORAGE_KEY)).not.toContain('Café')
    expect(inspectWorkspaceStorage(storage).kind).toBe('encrypted')
    await expect(decryptWorkspace(envelope, 'incorrecta-123')).rejects.toThrow('contraseña')
    const restored = await decryptWorkspace(envelope, 'rumbo-seguro-123')
    expect(restored.workspace.financial.movements[0].name).toBe('Café')
  })

  it('keeps a legacy workspace available for explicit migration', () => {
    const storage = new MemoryStorage()
    saveWorkspace(workspace, storage)
    const inspection = inspectWorkspaceStorage(storage)
    expect(inspection.kind).toBe('legacy')
    expect(readWorkspace(storage).value?.profile.name).toBe('Bryan')
  })
})
