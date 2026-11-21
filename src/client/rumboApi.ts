import { ApiTransportError, createApiTransport, type ApiRequest, type ApiTransport } from './apiTransport'

type JsonRecord = Record<string, unknown>

export type ApiItem<T = unknown> = { data: T; requestId: string }
export type ApiPage<T = unknown> = { data: T[]; requestId: string; nextCursor: string | null; hasMore: boolean }
export type HnlMovementWrite = {
  transactionType: 'income' | 'expense'
  name: string
  category: string
  amount: string
  currency: 'HNL'
  date: string
  status?: 'confirmed' | 'planned'
  sourceType?: 'manual' | 'quick-expense'
  recurrence?: 'one-time' | 'daily' | 'weekly' | 'biweekly' | 'monthly'
  isQuickExpense?: boolean
  notes?: string | null
}

export type ServerProfile = { id: string; email: string | null; displayName: string; currency: 'HNL'; accountStatus: string; onboardingCompleted: boolean }
export type ServerSession = { profile: ServerProfile; session: { expiresAt: string }; csrfToken: string }
export type RegistrationRequest = { email: string; password: string; displayName: string }
export type RegistrationResult = { status: 'pending-verification' }
export type ServerMovement = Omit<HnlMovementWrite, 'sourceType'> & { id: string; userId: string; sourceType: 'manual' | 'quick-expense' | 'reminder-payment'; createdAt: string; updatedAt: string }
export type ServerSummary = { currency: 'HNL'; confirmedIncome: string; confirmedExpenses: string; totalBalance: string; savingsAllocated: string; availableBalance: string; pendingObligations: string; projectedBalance: string }

export class RumboApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: unknown,
    readonly requestId: string | null,
  ) {
    super(message)
    this.name = 'RumboApiError'
  }
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function contractError(payload: unknown): never {
  throw new ApiTransportError('invalid-response', null, payload)
}

function item<T = unknown>(payload: unknown): ApiItem<T> {
  if (!isRecord(payload) || !Object.prototype.hasOwnProperty.call(payload, 'data') || !isRecord(payload.meta) || typeof payload.meta.requestId !== 'string') contractError(payload)
  return { data: payload.data as T, requestId: payload.meta.requestId }
}

function page<T = unknown>(payload: unknown): ApiPage<T> {
  const result = item<T[]>(payload)
  const meta = (payload as JsonRecord).meta as JsonRecord
  if (!Array.isArray(result.data) || typeof meta.hasMore !== 'boolean' || !(meta.nextCursor === null || typeof meta.nextCursor === 'string')) contractError(payload)
  return { ...result, nextCursor: meta.nextCursor as string | null, hasMore: meta.hasMore }
}

function collection<T = unknown>(payload: unknown): ApiItem<T[]> {
  const result = item<T[]>(payload)
  if (!Array.isArray(result.data)) contractError(payload)
  return result
}

function mapHttpError(error: unknown): never {
  if (error instanceof ApiTransportError && error.failure === 'http' && isRecord(error.response) && isRecord(error.response.error)) {
    const details = error.response.error
    if (typeof details.code === 'string' && typeof details.message === 'string') {
      throw new RumboApiError(error.status || 0, details.code, details.message, details.details ?? null, typeof details.requestId === 'string' ? details.requestId : null)
    }
  }
  throw error
}

function validateMovementWrite(value: JsonRecord, creating: boolean) {
  if ('userId' in value || 'id' in value) throw new TypeError('El servidor asigna el titular y el ID del movimiento.')
  if ('currency' in value && value.currency !== 'HNL') throw new TypeError('La moneda principal debe ser HNL.')
  if (creating && value.currency !== 'HNL') throw new TypeError('La moneda principal debe ser HNL.')
  if ('amount' in value || creating) value.amount = normalizeHnl(value.amount)
  if (creating && (!['income', 'expense'].includes(String(value.transactionType)) || typeof value.name !== 'string' || !value.name.trim() || typeof value.category !== 'string' || !value.category.trim() || !/^\d{4}-\d{2}-\d{2}$/.test(String(value.date)))) {
    throw new TypeError('Faltan campos válidos para registrar el movimiento.')
  }
}

export function normalizeHnl(value: unknown): string {
  if (typeof value !== 'string') throw new TypeError('El importe HNL debe enviarse como cadena decimal.')
  const match = /^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/.exec(value.trim())
  if (!match) throw new TypeError('El importe HNL debe tener hasta dos decimales.')
  const amount = `${match[1]}.${(match[2] || '').padEnd(2, '0')}`
  if (amount === '0.00') throw new TypeError('El importe HNL debe ser mayor que cero.')
  return amount
}

function resourceId(id: string) {
  if (!id.trim()) throw new TypeError('Falta el ID del recurso.')
  return encodeURIComponent(id)
}

export function createRumboApi(transport: ApiTransport = createApiTransport(globalThis.fetch, import.meta.env.BASE_URL)) {
  async function request(path: string, options?: ApiRequest) {
    try { return await transport.request(path, options) } catch (error) { mapHttpError(error) }
  }

  return {
    register: async (registration: RegistrationRequest) => item<RegistrationResult>(await request('/api/v1/auth/register', { method: 'POST', body: registration })),
    session: async () => {
      const result = item<ServerSession>(await request('/api/v1/auth/session'))
      transport.setCsrfToken?.(result.data.csrfToken)
      return result
    },
    login: async (email: string, password: string) => {
      const result = item<ServerSession>(await request('/api/v1/auth/login', { method: 'POST', body: { email, password } }))
      transport.setCsrfToken?.(result.data.csrfToken)
      return result
    },
    logout: async () => {
      const result = item(await request('/api/v1/auth/logout', { method: 'POST' }))
      transport.setCsrfToken?.(null)
      return result
    },
    profile: async () => item<ServerProfile>(await request('/api/v1/me/profile')),
    financialSummary: async () => item<ServerSummary>(await request('/api/v1/financial-summary')),
    movements: async (cursor?: string) => page<ServerMovement>(await request(`/api/v1/movements${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`)),
    createMovement: async (movement: HnlMovementWrite, idempotencyKey: string) => {
      const body: JsonRecord = { ...movement }
      validateMovementWrite(body, true)
      if (!idempotencyKey.trim()) throw new TypeError('Falta la clave de idempotencia.')
      return item<ServerMovement>(await request('/api/v1/movements', { method: 'POST', body, headers: { 'Idempotency-Key': idempotencyKey } }))
    },
    updateMovement: async (id: string, patch: JsonRecord) => {
      const body = { ...patch }
      validateMovementWrite(body, false)
      return item<ServerMovement>(await request(`/api/v1/movements/${resourceId(id)}`, { method: 'PATCH', body }))
    },
    deleteMovement: async (id: string) => request(`/api/v1/movements/${resourceId(id)}`, { method: 'DELETE' }),
    budgets: async () => collection(await request('/api/v1/budgets')),
    goals: async () => collection(await request('/api/v1/goals')),
    reminders: async () => collection(await request('/api/v1/reminders')),
    dailyReport: async () => item(await request('/api/v1/reports/daily')),
    notifications: async () => collection(await request('/api/v1/notifications')),
  }
}
