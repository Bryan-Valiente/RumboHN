import { describe, expect, it, vi } from 'vitest'
import { createApiTransport } from '../src/client/apiTransport'

describe('client API transport', () => {
  it('uses the same origin and leaves response validation to the agreed endpoint adapter', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ items: [{ id: 'm1' }] }), { status: 200 }))
    const transport = createApiTransport(fetcher as typeof fetch)

    await expect(transport.request('/future/movements')).resolves.toEqual({ items: [{ id: 'm1' }] })
    expect(fetcher).toHaveBeenCalledOnce()
    expect(fetcher.mock.calls[0][0]).toBe('/future/movements')
    expect(fetcher.mock.calls[0][1]).toMatchObject({ method: 'GET', credentials: 'same-origin' })
  })

  it('rejects external URLs before making a request', async () => {
    const fetcher = vi.fn()
    const transport = createApiTransport(fetcher as typeof fetch)

    await expect(transport.request('https://example.com/api')).rejects.toThrow('mismo origen')
    await expect(transport.request('//example.com/api')).rejects.toThrow('mismo origen')
    await expect(transport.request('/\\example.com/api')).rejects.toThrow('mismo origen')
    expect(fetcher).not.toHaveBeenCalled()
  })

  it('serializes mutations without putting data in the URL', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ id: 'm1' }), { status: 201 }))
    const transport = createApiTransport(fetcher as typeof fetch)

    await transport.request('/future/movements', { method: 'POST', body: { amountCents: 1250 } })
    const [path, options] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(path).toBe('/future/movements')
    expect(options.body).toBe(JSON.stringify({ amountCents: 1250 }))
    expect(new Headers(options.headers).get('Content-Type')).toBe('application/json')
  })

  it('preserves an unauthorized response for the future session adapter without retrying', async () => {
    const payload = { error: { code: 'SESSION_EXPIRED' } }
    const fetcher = vi.fn(async () => new Response(JSON.stringify(payload), { status: 401 }))
    const transport = createApiTransport(fetcher as typeof fetch)

    await expect(transport.request('/future/session')).rejects.toMatchObject({ failure: 'http', status: 401, response: payload })
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('handles an empty success and distinguishes malformed JSON', async () => {
    const empty = createApiTransport(vi.fn(async () => new Response(null, { status: 204 })) as typeof fetch)
    const malformed = createApiTransport(vi.fn(async () => new Response('<html>login</html>', { status: 200 })) as typeof fetch)

    await expect(empty.request('/future/logout', { method: 'POST' })).resolves.toBeNull()
    await expect(malformed.request('/future/me')).rejects.toMatchObject({ failure: 'invalid-response', status: 200 })
  })

  it('reports connection failures without exposing the original request body', async () => {
    const fetcher = vi.fn(async () => { throw new Error('connection failed') })
    const transport = createApiTransport(fetcher as typeof fetch)

    await expect(transport.request('/future/movements', { method: 'POST', body: { amount: 100 } })).rejects.toMatchObject({ failure: 'network', status: null, response: null })
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('applies the Hostinger base path and CSRF header after session discovery', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ data: {}, meta: { requestId: 'r1' } }), { status: 200 }))
    const transport = createApiTransport(fetcher as typeof fetch, '/demos/rumbo-hn/')
    await transport.request('/api/v1/auth/session')
    transport.setCsrfToken?.('csrf-value')
    await transport.request('/api/v1/movements', { method: 'POST', body: { amount: '1.00' } })
    expect(fetcher.mock.calls[0][0]).toBe('/demos/rumbo-hn/api/v1/auth/session')
    expect(fetcher.mock.calls[1][0]).toBe('/demos/rumbo-hn/api/v1/movements')
    expect(new Headers((fetcher.mock.calls[1][1] as RequestInit).headers).get('X-CSRF-Token')).toBe('csrf-value')
  })
})
