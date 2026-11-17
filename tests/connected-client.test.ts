import { describe, expect, it, vi } from 'vitest'
import { createApiTransport } from '../src/client/apiTransport'
import { createRumboApi } from '../src/client/rumboApi'

const response = (data: unknown, status = 200, meta: Record<string, unknown> = {}) => new Response(JSON.stringify({ data, meta: { requestId: 'test-request', ...meta } }), { status })

describe('connected client against B01 response shapes', () => {
  it('uses the session CSRF token and repeats the same movement key on a safe retry', async () => {
    let createAttempts = 0
    const fetcher = vi.fn(async (path: string, options?: RequestInit) => {
      if (path.endsWith('/auth/session')) return response({ profile: { id: 'u1', displayName: 'Prueba' }, session: { expiresAt: '2026-12-01T00:00:00Z' }, csrfToken: 'csrf-test' })
      if (path.endsWith('/movements') && options?.method === 'POST') {
        createAttempts++
        if (createAttempts === 1) throw new Error('uncertain network result')
        return response({ id: 'm1', amount: '12.50', currency: 'HNL' }, 201)
      }
      throw new Error(`Unexpected route: ${path}`)
    })
    const api = createRumboApi(createApiTransport(fetcher as typeof fetch, '/demos/rumbo-hn/'))
    await api.session()
    const movement = { transactionType: 'expense' as const, name: 'Prueba', category: 'Otros', amount: '12.50', currency: 'HNL' as const, date: '2026-11-16' }
    await expect(api.createMovement(movement, 'same-attempt-key')).rejects.toMatchObject({ failure: 'network' })
    await expect(api.createMovement(movement, 'same-attempt-key')).resolves.toMatchObject({ data: { id: 'm1' } })
    const calls = fetcher.mock.calls.slice(1) as Array<[string, RequestInit]>
    for (const [path, options] of calls) {
      expect(path).toBe('/demos/rumbo-hn/api/v1/movements')
      expect(new Headers(options.headers).get('X-CSRF-Token')).toBe('csrf-test')
      expect(new Headers(options.headers).get('Idempotency-Key')).toBe('same-attempt-key')
      expect(options.credentials).toBe('same-origin')
      expect(JSON.parse(String(options.body))).toMatchObject({ amount: '12.50', currency: 'HNL' })
    }
  })

  it('does not send a CSRF header before the login response and clears it after logout', async () => {
    const fetcher = vi.fn(async (path: string) => path.endsWith('/auth/login')
      ? response({ profile: { id: 'u1' }, session: { expiresAt: '2026-12-01T00:00:00Z' }, csrfToken: 'csrf-after-login' })
      : response({ loggedOut: true }))
    const api = createRumboApi(createApiTransport(fetcher as typeof fetch))
    await api.login('prueba@rumbo.invalid', 'example-only-password')
    await api.logout()
    const loginOptions = fetcher.mock.calls[0][1] as RequestInit
    const logoutOptions = fetcher.mock.calls[1][1] as RequestInit
    expect(new Headers(loginOptions.headers).has('X-CSRF-Token')).toBe(false)
    expect(new Headers(logoutOptions.headers).get('X-CSRF-Token')).toBe('csrf-after-login')
  })
})
