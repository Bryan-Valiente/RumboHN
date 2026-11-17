import { describe, expect, it, vi } from 'vitest'
import { ApiTransportError, type ApiTransport } from '../src/client/apiTransport'
import { createRumboApi, RumboApiError } from '../src/client/rumboApi'

const meta = { requestId: 'req-1' }

describe('Rumbo API contract adapter', () => {
  it('uses B01 routes and unwraps its session and paginated movement envelopes', async () => {
    const request = vi.fn(async (path: string) => path.endsWith('/movements')
      ? { data: [{ id: 'm1', amount: 15000, currency: 'HNL' }], meta: { ...meta, nextCursor: null, hasMore: false } }
      : { data: { id: 'session-1' }, meta })
    const api = createRumboApi({ request } as ApiTransport)

    await expect(api.session()).resolves.toEqual({ data: { id: 'session-1' }, requestId: 'req-1' })
    await expect(api.movements()).resolves.toEqual({ data: [{ id: 'm1', amount: 15000, currency: 'HNL' }], requestId: 'req-1', nextCursor: null, hasMore: false })
    expect(request.mock.calls.map(([path]) => path)).toEqual(['/api/v1/auth/session', '/api/v1/movements'])
  })

  it('sends HNL amounts as lempiras with two-decimal precision and no user ID', async () => {
    const request = vi.fn(async () => ({ data: { id: 'm2', amount: 1250.25, currency: 'HNL' }, meta }))
    const api = createRumboApi({ request } as ApiTransport)
    const movement = { name: 'Ingreso', kind: 'income', amount: 1250.25, currency: 'HNL' as const, date: '2026-11-15' }

    await api.createMovement(movement)
    expect(request).toHaveBeenCalledWith('/api/v1/movements', { method: 'POST', body: movement })
    await expect(api.createMovement({ ...movement, amount: 1.005 })).rejects.toThrow('dos decimales')
    await expect(api.createMovement({ ...movement, currency: 'USD' as 'HNL' })).rejects.toThrow('HNL')
    await expect(api.createMovement({ ...movement, userId: 'otro' })).rejects.toThrow('titular')
    expect(request).toHaveBeenCalledOnce()
  })

  it('encodes opaque movement IDs and never accepts a changed owner or ID in a patch', async () => {
    const request = vi.fn(async () => ({ data: { id: 'm/2' }, meta }))
    const api = createRumboApi({ request } as ApiTransport)

    await api.updateMovement('m/2', { amount: 12.5 })
    expect(request).toHaveBeenCalledWith('/api/v1/movements/m%2F2', { method: 'PATCH', body: { amount: 12.5 } })
    await expect(api.updateMovement('m/2', { id: 'otro' })).rejects.toThrow('ID')
    expect(request).toHaveBeenCalledOnce()
  })

  it('maps the agreed error envelope without turning an unauthorized response into success', async () => {
    const error = new ApiTransportError('http', 401, { error: { code: 'AUTH_REQUIRED', message: 'Iniciá sesión.', requestId: 'req-2' } })
    const api = createRumboApi({ request: vi.fn(async () => { throw error }) } as ApiTransport)

    await expect(api.profile()).rejects.toMatchObject({ status: 401, code: 'AUTH_REQUIRED', requestId: 'req-2' })
    await expect(api.profile()).rejects.toBeInstanceOf(RumboApiError)
  })

  it('rejects malformed collection envelopes before client modules use them', async () => {
    const api = createRumboApi({ request: vi.fn(async () => ({ data: {}, meta })) } as ApiTransport)

    await expect(api.reminders()).rejects.toMatchObject({ failure: 'invalid-response' })
  })
})
