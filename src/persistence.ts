import { defaultNotificationPreferences, emptyFinancialState, type FinancialState } from './finance'

export const WORKSPACE_STORAGE_KEY = 'rumbo-hn-personal-workspace-v1'
export const TRIAL_DURATION_MS = 7 * 24 * 60 * 60 * 1000

export type DemoProfile = {
  name: string
  preferredGoals: string[]
  incomeFrequency: string
  incomeAmount: number
  createdAt: number
  trialEndsAt: number
  onboardingCompleted: boolean
}

export type SimulatedSubscription = {
  planId: string
  activatedAt: number
  status: 'active'
}

export type PersistedWorkspace = {
  version: 1
  profile: DemoProfile
  financial: FinancialState
  subscription: SimulatedSubscription | null
}

export type StorageResult<T> = { value: T; error: string | null }

function getStorage(storage?: Pick<Storage, 'getItem' | 'setItem'> | null) {
  if (storage !== undefined) return storage
  if (typeof window === 'undefined') return null
  try { return window.localStorage } catch { return null }
}

export function normalizeFinancialState(value: Partial<FinancialState> | null | undefined): FinancialState {
  return {
    ...emptyFinancialState,
    ...(value || {}),
    notificationPreferences: { ...defaultNotificationPreferences, ...(value?.notificationPreferences || {}) },
    movements: Array.isArray(value?.movements) ? value.movements : [],
    budgets: Array.isArray(value?.budgets) ? value.budgets : [],
    goals: Array.isArray(value?.goals) ? value.goals : [],
    contributions: Array.isArray(value?.contributions) ? value.contributions : [],
    reminders: Array.isArray(value?.reminders) ? value.reminders : [],
    templates: Array.isArray(value?.templates) ? value.templates : [],
  }
}

export function parseWorkspace(raw: string | null): PersistedWorkspace | null {
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as Partial<PersistedWorkspace>
    if (!parsed.profile || parsed.profile.onboardingCompleted !== true) return null
    return {
      version: 1,
      profile: parsed.profile,
      financial: normalizeFinancialState(parsed.financial),
      subscription: parsed.subscription?.status === 'active' ? parsed.subscription : null,
    }
  } catch {
    return null
  }
}

export function readWorkspace(storage?: Pick<Storage, 'getItem' | 'setItem'> | null): StorageResult<PersistedWorkspace | null> {
  const target = getStorage(storage)
  if (!target) return { value: null, error: 'El almacenamiento local no está disponible en este navegador.' }
  try { return { value: parseWorkspace(target.getItem(WORKSPACE_STORAGE_KEY)), error: null } } catch { return { value: null, error: 'No se pudo leer tu espacio local. Podés continuar, pero los cambios podrían no persistir.' } }
}

export function saveWorkspace(workspace: PersistedWorkspace, storage?: Pick<Storage, 'getItem' | 'setItem'> | null): StorageResult<boolean> {
  const target = getStorage(storage)
  if (!target) return { value: false, error: 'El almacenamiento local no está disponible en este navegador.' }
  try { target.setItem(WORKSPACE_STORAGE_KEY, JSON.stringify(workspace)); return { value: true, error: null } } catch { return { value: false, error: 'No se pudo guardar tu espacio local. Revisá el almacenamiento del navegador.' } }
}

export function todayKey(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Tegucigalpa', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date)
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

export function trialDaysRemaining(profile: DemoProfile, now = Date.now()) { return Math.max(0, Math.ceil((profile.trialEndsAt - now) / 86400000)) }
export function trialExpired(profile: DemoProfile, now = Date.now()) { return profile.trialEndsAt <= now }
