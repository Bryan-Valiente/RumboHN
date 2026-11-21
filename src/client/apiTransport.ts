export type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export type ApiRequest = {
  method?: ApiMethod
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
}

export type ApiFailure = 'http' | 'network' | 'invalid-response'

export class ApiTransportError extends Error {
  constructor(
    readonly failure: ApiFailure,
    readonly status: number | null = null,
    readonly response: unknown = null,
  ) {
    super(failure === 'network' ? 'No se pudo conectar con el servidor.' : failure === 'invalid-response' ? 'El servidor devolvió una respuesta no válida.' : `El servidor respondió con estado ${status}.`)
    this.name = 'ApiTransportError'
  }
}

export type ApiTransport = {
  request(path: string, options?: ApiRequest): Promise<unknown>
  setCsrfToken?(token: string | null): void
}

// The route map and response validators belong to the shared B01/O01 contract.
// Keeping this boundary unused preserves the existing encrypted local workspace.
export function createApiTransport(fetchImpl: typeof fetch = globalThis.fetch, basePath = '/'): ApiTransport {
  if (!basePath.startsWith('/') || !basePath.endsWith('/') || basePath.startsWith('//') || basePath.includes('..')) {
    throw new TypeError('La base del API debe ser una ruta local terminada en /.')
  }
  let csrfToken: string | null = null
  return {
    setCsrfToken(token) { csrfToken = token },
    async request(path, options = {}) {
      if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\') || path.includes('#')) {
        throw new TypeError('La ruta del API debe ser relativa al mismo origen.')
      }

      const headers = new Headers(options.headers)
      headers.set('Accept', 'application/json')
      const hasBody = Object.prototype.hasOwnProperty.call(options, 'body')
      if (hasBody) headers.set('Content-Type', 'application/json')
      if ((options.method || 'GET') !== 'GET' && csrfToken) headers.set('X-CSRF-Token', csrfToken)

      let result: Response
      try {
        result = await fetchImpl(basePath === '/' ? path : basePath.slice(0, -1) + path, {
          method: options.method || 'GET',
          credentials: 'same-origin',
          headers,
          body: hasBody ? JSON.stringify(options.body) : undefined,
          signal: options.signal,
        })
      } catch (error) {
        if (error instanceof Error && error.name === 'AbortError') throw error
        throw new ApiTransportError('network')
      }

      if (result.status === 204) return null

      let payload: unknown = null
      try {
        const text = await result.text()
        if (text) payload = JSON.parse(text) as unknown
      } catch {
        if (!result.ok) throw new ApiTransportError('http', result.status)
        throw new ApiTransportError('invalid-response', result.status)
      }

      if (!result.ok) throw new ApiTransportError('http', result.status, payload)
      if (payload === null) throw new ApiTransportError('invalid-response', result.status)
      return payload
    },
  }
}
