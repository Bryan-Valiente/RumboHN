import { ApiTransportError, type ApiTransport } from './apiTransport'

// O01 test fixture. It is never selected automatically or used as a real account.
const fixture = {
  profile: { id: 'mock-user', email: 'prueba@rumbo.invalid', displayName: 'Persona de prueba', currency: 'HNL', accountStatus: 'active', onboardingCompleted: true },
  movements: [
    { id: 'mock-movement-1', amount: '1250.50', currency: 'HNL', transactionType: 'income', name: 'Ingreso de ejemplo', date: '2026-01-15' },
    { id: 'mock-movement-2', amount: '125.25', currency: 'HNL', transactionType: 'expense', name: 'Gasto de ejemplo', date: '2026-01-16' },
  ],
  financialSummary: { currency: 'HNL', confirmedIncome: '1250.50', confirmedExpenses: '125.25', totalBalance: '1125.25', savingsAllocated: '0.00', availableBalance: '1125.25', pendingObligations: '0.00', projectedBalance: '1125.25' },
} as const

const item = (data: unknown) => ({ data, meta: { requestId: 'mock-request' } })
const page = (data: unknown[]) => ({ data, meta: { requestId: 'mock-request', nextCursor: null, hasMore: false } })

export function createMockApiTransport(): ApiTransport {
  return {
    async request(path, options = {}) {
      if (options.method && options.method !== 'GET') throw new ApiTransportError('http', 405)
      switch (path) {
        case '/api/v1/auth/session': return item({ profile: fixture.profile, session: { expiresAt: '2026-12-01T00:00:00Z' }, csrfToken: 'mock-csrf' })
        case '/api/v1/me/profile': return item(fixture.profile)
        case '/api/v1/movements': return page([...fixture.movements])
        case '/api/v1/financial-summary': return item(fixture.financialSummary)
        default: throw new ApiTransportError('http', 404)
      }
    },
  }
}
