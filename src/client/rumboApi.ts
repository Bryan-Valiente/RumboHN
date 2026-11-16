import { ApiTransportError, createApiTransport, type ApiRequest, type ApiTransport } from './apiTransport'

type JsonRecord = Record<string, unknown>

export type ApiItem<T = unknown> = { data: T; requestId: string }
export type ApiPage<T = unknown> = { data: T[]; requestId: string; nextCursor: string | null; hasMore: boolean }
export type HnlMovementWrite = JsonRecord & { amount: number; currency: 'HNL' }

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
  if ('amount' in value || creating) {
    const amount = value.amount
    if (typeof amount !== 'number' || !Number.isFinite(amount) || amount <= 0 || Math.abs(amount - Math.round(amount * 100) / 100) > 1e-9) {
      throw new TypeError('El importe HNL debe ser positivo y tener hasta dos decimales.')
    }
  }
}

function resourceId(id: string) {
  if (!id.trim()) throw new TypeError('Falta el ID del recurso.')
  return encodeURIComponent(id)
}

export function createRumboApi(transport: ApiTransport = createApiTransport()) {
  async function request(path: string, options?: ApiRequest) {
    try { return await transport.request(path, options) } catch (error) { mapHttpError(error) }
  }

  return {
    session: async () => item(await request('/api/v1/auth/session')),
    profile: async () => item(await request('/api/v1/me/profile')),
    movements: async () => page(await request('/api/v1/movements')),
    createMovement: async (movement: HnlMovementWrite) => {
      validateMovementWrite(movement, true)
      return item(await request('/api/v1/movements', { method: 'POST', body: movement }))
    },
    updateMovement: async (id: string, patch: JsonRecord) => {
      validateMovementWrite(patch, false)
      return item(await request(`/api/v1/movements/${resourceId(id)}`, { method: 'PATCH', body: patch }))
    },
    deleteMovement: async (id: string) => request(`/api/v1/movements/${resourceId(id)}`, { method: 'DELETE' }),
    budgets: async () => page(await request('/api/v1/budgets')),
    goals: async () => page(await request('/api/v1/goals')),
    reminders: async () => page(await request('/api/v1/reminders')),
    dailyReport: async () => item(await request('/api/v1/reports/daily')),
    notifications: async () => page(await request('/api/v1/notifications')),
  }
}
