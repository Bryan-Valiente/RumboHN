import { describe, expect, it } from 'vitest'
import { createMockApiTransport } from '../src/client/mockTransport'
import { createRumboApi } from '../src/client/rumboApi'

describe('O01 mock API', () => {
  const api = createRumboApi(createMockApiTransport())

  it('returns recognizable fixture identity and paginated HNL movements', async () => {
    const session = await api.session()
    const profile = await api.profile()
    const movements = await api.movements()
    expect(session.data).toMatchObject({ user: { id: 'mock-user' } })
    expect(profile.data).toMatchObject({ currency: 'HNL' })
    expect(movements).toMatchObject({ hasMore: false, nextCursor: null })
    expect(movements.data).toHaveLength(2)
    expect(movements.data).toEqual(expect.arrayContaining([expect.objectContaining({ amount: 1250.5, currency: 'HNL' })]))
  })

  it('does not pretend that mock writes are persisted', async () => {
    await expect(api.createMovement({ amount: 12.34, currency: 'HNL' })).rejects.toMatchObject({ status: 405 })
    expect((await api.movements()).data).toHaveLength(2)
  })
})
