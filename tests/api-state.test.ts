import { describe, expect, it } from 'vitest'
import { clientRequestError } from '../src/client/apiState'
import { ApiTransportError } from '../src/client/apiTransport'
import { RumboApiError } from '../src/client/rumboApi'

describe('client request states', () => {
  it('distinguishes expired sessions from connection failures', () => {
    expect(clientRequestError(new RumboApiError(401, 'AUTH_REQUIRED', 'error', null, 'r1')).kind).toBe('unauthorized')
    expect(clientRequestError(new ApiTransportError('network')).kind).toBe('unavailable')
  })

  it('maps permission, validation and conflict without displaying technical details', () => {
    expect(clientRequestError(new RumboApiError(403, 'FORBIDDEN', 'error', null, 'r2')).kind).toBe('forbidden')
    expect(clientRequestError(new RumboApiError(422, 'VALIDATION_ERROR', 'Revisá el importe.', null, 'r3'))).toEqual({ kind: 'validation', message: 'Revisá el importe.' })
    expect(clientRequestError(new RumboApiError(409, 'CONFLICT', 'error', null, 'r4')).kind).toBe('conflict')
  })
})
