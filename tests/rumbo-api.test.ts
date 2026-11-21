import { describe, expect, it, vi } from 'vitest'
import { ApiTransportError, type ApiTransport } from '../src/client/apiTransport'
import { createRumboApi, normalizeHnl, RumboApiError } from '../src/client/rumboApi'

const meta = { requestId: 'req-1' }
const movement = { transactionType: 'income' as const, name: 'Ingreso', category: 'Salario', amount: '1250.25', currency: 'HNL' as const, date: '2026-11-15' }

describe('Rumbo API contract adapter', () => {
  it('submits account registration to B01 and keeps it pending activation', async () => {
    const request = vi.fn(async () => ({ data: { status: 'pending-verification' }, meta }))
    const api = createRumboApi({ request } as ApiTransport)
    const registration = { displayName: 'Owen Prueba', email: 'owen@example.com', password: 'a-valid-test-password' }

    await expect(api.register(registration)).resolves.toMatchObject({ data: { status: 'pending-verification' } })
    expect(request).toHaveBeenCalledWith('/api/v1/auth/register', { method: 'POST', body: registration })
  })
  it('uses B01 session and paginated movement envelopes', async () => {
    const request = vi.fn(async (path: string) => path.endsWith('/movements')
      ? { data: [{ id: 'm1', amount: '15000.00', currency: 'HNL' }], meta: { ...meta, nextCursor: null, hasMore: false } }
      : { data: { profile: { id: 'u1' }, csrfToken: 'csrf-1' }, meta })
    const setCsrfToken = vi.fn()
    const api = createRumboApi({ request, setCsrfToken })

    await expect(api.session()).resolves.toMatchObject({ data: { profile: { id: 'u1' } }, requestId: 'req-1' })
    expect(setCsrfToken).toHaveBeenCalledWith('csrf-1')
    await expect(api.movements()).resolves.toMatchObject({ data: [{ id: 'm1', amount: '15000.00' }], nextCursor: null, hasMore: false })
    expect(request.mock.calls.map(([path]) => path)).toEqual(['/api/v1/auth/session', '/api/v1/movements'])
  })

  it('sends canonical HNL strings and a stable idempotency key', async () => {
    const request = vi.fn(async () => ({ data: { id: 'm2', amount: '1250.25', currency: 'HNL' }, meta }))
    const api = createRumboApi({ request } as ApiTransport)

    await api.createMovement(movement, 'attempt-1')
    expect(request).toHaveBeenCalledWith('/api/v1/movements', { method: 'POST', body: movement, headers: { 'Idempotency-Key': 'attempt-1' } })
    await expect(api.createMovement({ ...movement, amount: '1.005' }, 'attempt-2')).rejects.toThrow('dos decimales')
    await expect(api.createMovement({ ...movement, amount: 1.25 as unknown as string }, 'attempt-2')).rejects.toThrow('cadena decimal')
    await expect(api.createMovement({ ...movement, currency: 'USD' as 'HNL' }, 'attempt-2')).rejects.toThrow('HNL')
    await expect(api.createMovement({ ...movement, userId: 'otro' }, 'attempt-2')).rejects.toThrow('titular')
    expect(request).toHaveBeenCalledOnce()
  })

  it('normalizes permitted decimal strings without floating point conversion', () => {
    expect(normalizeHnl('12')).toBe('12.00')
    expect(normalizeHnl('12.5')).toBe('12.50')
    expect(normalizeHnl('9999999999999.99')).toBe('9999999999999.99')
    expect(() => normalizeHnl('0.00')).toThrow('mayor que cero')
    expect(() => normalizeHnl('1e3')).toThrow('dos decimales')
  })

  it('encodes opaque IDs and validates patch amounts', async () => {
    const request = vi.fn(async () => ({ data: { id: 'm/2' }, meta }))
    const api = createRumboApi({ request } as ApiTransport)
    await api.updateMovement('m/2', { amount: '12.5' })
    expect(request).toHaveBeenCalledWith('/api/v1/movements/m%2F2', { method: 'PATCH', body: { amount: '12.50' } })
    await expect(api.updateMovement('m/2', { id: 'otro' })).rejects.toThrow('ID')
    expect(request).toHaveBeenCalledOnce()
  })

  it('maps the B01 error envelope', async () => {
    const error = new ApiTransportError('http', 401, { error: { code: 'AUTH_REQUIRED', message: 'Iniciá sesión.', requestId: 'req-2' } })
    const api = createRumboApi({ request: vi.fn(async () => { throw error }) } as ApiTransport)
    await expect(api.profile()).rejects.toMatchObject({ status: 401, code: 'AUTH_REQUIRED', requestId: 'req-2' })
    await expect(api.profile()).rejects.toBeInstanceOf(RumboApiError)
  })

  it('rejects malformed collections', async () => {
    const api = createRumboApi({ request: vi.fn(async () => ({ data: {}, meta })) } as ApiTransport)
    await expect(api.reminders()).rejects.toMatchObject({ failure: 'invalid-response' })
  })
})
