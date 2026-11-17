import { ApiTransportError, type ApiTransport } from './apiTransport'

// O01 test fixture. It is never selected automatically or used as a real account.
const fixture = {
  session: { authenticated: true, user: { id: 'mock-user', email: 'prueba@rumbo.invalid' } },
  profile: { id: 'mock-user', name: 'Persona de prueba', currency: 'HNL' },
  movements: [
    { id: 'mock-movement-1', amount: 1250.50, currency: 'HNL', kind: 'income', name: 'Ingreso de ejemplo', date: '2026-01-15' },
    { id: 'mock-movement-2', amount: 125.25, currency: 'HNL', kind: 'expense', name: 'Gasto de ejemplo', date: '2026-01-16' },
  ],
  dailyReport: { date: '2026-01-16', currency: 'HNL', income: 1250.50, expenses: 125.25, balance: 1125.25 },
} as const

const item = (data: unknown) => ({ data, meta: { requestId: 'mock-request' } })
const page = (data: unknown[]) => ({ data, meta: { requestId: 'mock-request', nextCursor: null, hasMore: false } })

export function createMockApiTransport(): ApiTransport {
  return {
    async request(path, options = {}) {
      if (options.method && options.method !== 'GET') throw new ApiTransportError('http', 405)
      switch (path) {
        case '/api/v1/auth/session': return item(fixture.session)
        case '/api/v1/me/profile': return item(fixture.profile)
        case '/api/v1/movements': return page([...fixture.movements])
        case '/api/v1/reports/daily': return item(fixture.dailyReport)
        default: throw new ApiTransportError('http', 404)
      }
    },
  }
}
