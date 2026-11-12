import { defaultNotificationPreferences, emptyFinancialState, type FinancialState } from './finance'

export const WORKSPACE_STORAGE_KEY = 'rumbo-hn-personal-workspace-v1'
export const TRIAL_DURATION_MS = 7 * 24 * 60 * 60 * 1000
export const ENCRYPTED_WORKSPACE_SCHEMA = 'rumbo-hn-encrypted-workspace'
export const ENCRYPTED_WORKSPACE_VERSION = 2
export const PASSWORD_ITERATIONS = 120_000

export type DemoProfile = {
  name: string
  preferredGoals: string[]
  incomeFrequency: string
  incomeAmount: number
  createdAt: number
  trialEndsAt: number
  onboardingCompleted: boolean
  inactivityMinutes?: number
}

export type SimulatedSubscription = { planId: string; activatedAt: number; status: 'active' }
export type PersistedWorkspace = { version: 1 | 2; profile: DemoProfile; financial: FinancialState; subscription: SimulatedSubscription | null }
export type LegacyWorkspace = Omit<PersistedWorkspace, 'version'> & { version: 1 }

export type EncryptedWorkspaceEnvelope = {
  schema: typeof ENCRYPTED_WORKSPACE_SCHEMA
  version: typeof ENCRYPTED_WORKSPACE_VERSION
  algorithm: 'AES-GCM'
  kdf: 'PBKDF2-SHA-256'
  iterations: number
  salt: string
  iv: string
  ciphertext: string
  createdAt: number
}

export type StorageInspection =
  | { kind: 'empty' }
  | { kind: 'encrypted'; envelope: EncryptedWorkspaceEnvelope }
  | { kind: 'legacy'; workspace: LegacyWorkspace }
  | { kind: 'invalid'; error: string }

export type StorageResult<T> = { value: T; error: string | null }

function getStorage(storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null) {
  if (storage !== undefined) return storage
  if (typeof window === 'undefined') return null
  try { return window.localStorage } catch { return null }
}

function webCrypto() {
  if (typeof globalThis.crypto === 'undefined' || !globalThis.crypto.subtle) throw new Error('Web Crypto no está disponible en este navegador.')
  return globalThis.crypto
}

function bytesToBase64(bytes: Uint8Array) {
  let binary = ''
  bytes.forEach((byte) => { binary += String.fromCharCode(byte) })
  return btoa(binary)
}

function base64ToBytes(value: string) {
  const binary = atob(value)
  return Uint8Array.from(binary, (character) => character.charCodeAt(0))
}

function encode(value: string) { return new TextEncoder().encode(value) }
function decode(value: ArrayBuffer) { return new TextDecoder().decode(value) }

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

function normalizeProfile(value: Partial<DemoProfile>) {
  return {
    name: String(value.name || '').trim(),
    preferredGoals: Array.isArray(value.preferredGoals) ? value.preferredGoals : [],
    incomeFrequency: String(value.incomeFrequency || 'Mensual'),
    incomeAmount: typeof value.incomeAmount === 'number' && Number.isFinite(value.incomeAmount) ? value.incomeAmount : 0,
    createdAt: Number(value.createdAt) || Date.now(),
    trialEndsAt: Number(value.trialEndsAt) || Date.now() + TRIAL_DURATION_MS,
    onboardingCompleted: value.onboardingCompleted === true,
    inactivityMinutes: Number(value.inactivityMinutes) || 15,
  }
}

export function parseWorkspace(raw: string | null): LegacyWorkspace | null {
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as Partial<LegacyWorkspace>
    if (!parsed.profile || parsed.profile.onboardingCompleted !== true) return null
    return { version: 1, profile: normalizeProfile(parsed.profile), financial: normalizeFinancialState(parsed.financial), subscription: parsed.subscription?.status === 'active' ? parsed.subscription : null }
  } catch { return null }
}

export function toCurrentWorkspace(workspace: LegacyWorkspace | PersistedWorkspace): PersistedWorkspace {
  return { version: 2, profile: normalizeProfile(workspace.profile), financial: normalizeFinancialState(workspace.financial), subscription: workspace.subscription?.status === 'active' ? workspace.subscription : null }
}

export function inspectWorkspaceStorage(storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null): StorageInspection {
  const target = getStorage(storage)
  if (!target) return { kind: 'invalid', error: 'El almacenamiento local no está disponible en este navegador.' }
  try {
    const raw = target.getItem(WORKSPACE_STORAGE_KEY)
    if (!raw) return { kind: 'empty' }
    const parsed = JSON.parse(raw) as Partial<EncryptedWorkspaceEnvelope>
    if (parsed.schema === ENCRYPTED_WORKSPACE_SCHEMA && parsed.version === ENCRYPTED_WORKSPACE_VERSION && parsed.ciphertext && parsed.iv && parsed.salt) return { kind: 'encrypted', envelope: parsed as EncryptedWorkspaceEnvelope }
    const legacy = parseWorkspace(raw)
    return legacy ? { kind: 'legacy', workspace: legacy } : { kind: 'invalid', error: 'El espacio guardado no tiene un formato reconocible.' }
  } catch { return { kind: 'invalid', error: 'No se pudo leer el espacio local.' } }
}

export function readWorkspace(storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null): StorageResult<LegacyWorkspace | null> {
  const inspection = inspectWorkspaceStorage(storage)
  if (inspection.kind === 'legacy') return { value: inspection.workspace, error: null }
  if (inspection.kind === 'empty') return { value: null, error: null }
  if (inspection.kind === 'encrypted') return { value: null, error: 'Tu espacio está protegido. Desbloquealo para continuar.' }
  return { value: null, error: inspection.error }
}

export function validateWorkspace(workspace: PersistedWorkspace | LegacyWorkspace) {
  return Boolean(workspace.profile?.onboardingCompleted && workspace.profile?.name && Array.isArray(workspace.financial?.movements) && Array.isArray(workspace.financial?.reminders))
}

async function deriveWorkspaceKey(password: string, salt: Uint8Array, iterations = PASSWORD_ITERATIONS) {
  if (!password || password.length < 8) throw new Error('La contraseña debe tener al menos 8 caracteres.')
  const crypto = webCrypto()
  const material = await crypto.subtle.importKey('raw', encode(password), 'PBKDF2', false, ['deriveKey'])
  return crypto.subtle.deriveKey({ name: 'PBKDF2', salt: salt as BufferSource, iterations, hash: 'SHA-256' }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

export async function createWorkspaceKey(password: string) {
  const salt = webCrypto().getRandomValues(new Uint8Array(16))
  return { key: await deriveWorkspaceKey(password, salt), salt }
}

export async function encryptWorkspaceWithKey(workspace: PersistedWorkspace, key: CryptoKey, salt: Uint8Array, createdAt = Date.now()): Promise<EncryptedWorkspaceEnvelope> {
  if (!validateWorkspace(workspace)) throw new Error('No se puede cifrar un espacio incompleto.')
  const iv = webCrypto().getRandomValues(new Uint8Array(12))
  const ciphertext = await webCrypto().subtle.encrypt({ name: 'AES-GCM', iv }, key, encode(JSON.stringify(toCurrentWorkspace(workspace))))
  return { schema: ENCRYPTED_WORKSPACE_SCHEMA, version: ENCRYPTED_WORKSPACE_VERSION, algorithm: 'AES-GCM', kdf: 'PBKDF2-SHA-256', iterations: PASSWORD_ITERATIONS, salt: bytesToBase64(salt), iv: bytesToBase64(iv), ciphertext: bytesToBase64(new Uint8Array(ciphertext)), createdAt }
}

export async function encryptWorkspace(workspace: PersistedWorkspace, password: string) {
  const { key, salt } = await createWorkspaceKey(password)
  return encryptWorkspaceWithKey(workspace, key, salt)
}

export async function decryptWorkspace(envelope: EncryptedWorkspaceEnvelope, password: string): Promise<{ workspace: PersistedWorkspace; key: CryptoKey; salt: Uint8Array }> {
  try {
    const salt = base64ToBytes(envelope.salt)
    const key = await deriveWorkspaceKey(password, salt, envelope.iterations)
    const plain = await webCrypto().subtle.decrypt({ name: 'AES-GCM', iv: base64ToBytes(envelope.iv) }, key, base64ToBytes(envelope.ciphertext))
    const workspace = toCurrentWorkspace(JSON.parse(decode(plain)) as PersistedWorkspace)
    if (!validateWorkspace(workspace)) throw new Error('El espacio cifrado no pasó la validación.')
    return { workspace, key, salt }
  } catch { throw new Error('La contraseña no es correcta o el respaldo está dañado.') }
}

export async function saveEncryptedWorkspace(workspace: PersistedWorkspace, key: CryptoKey, salt: Uint8Array, storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null): Promise<StorageResult<boolean>> {
  const target = getStorage(storage)
  if (!target) return { value: false, error: 'El almacenamiento local no está disponible en este navegador.' }
  try {
    target.setItem(WORKSPACE_STORAGE_KEY, JSON.stringify(await encryptWorkspaceWithKey(workspace, key, salt)))
    return { value: true, error: null }
  } catch (error) { return { value: false, error: error instanceof Error ? error.message : 'No se pudo guardar el espacio cifrado.' } }
}

export async function saveEncryptedWorkspaceWithPassword(workspace: PersistedWorkspace, password: string, storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null) {
  const { key, salt } = await createWorkspaceKey(password)
  const result = await saveEncryptedWorkspace(workspace, key, salt, storage)
  return { ...result, key, salt }
}

export async function createEncryptedBackup(workspace: PersistedWorkspace, password: string) {
  return JSON.stringify(await encryptWorkspace(workspace, password), null, 2)
}

// Legacy sync helper retained only for migration tests and old callers. The app uses saveEncryptedWorkspace.
export function saveWorkspace(workspace: PersistedWorkspace | LegacyWorkspace, storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null): StorageResult<boolean> {
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
