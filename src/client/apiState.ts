import { ApiTransportError } from './apiTransport'
import { RumboApiError } from './rumboApi'

export type ClientRequestState =
  | { kind: 'loading' }
  | { kind: 'ready' }
  | { kind: 'unauthorized'; message: string }
  | { kind: 'forbidden'; message: string }
  | { kind: 'validation'; message: string }
  | { kind: 'conflict'; message: string }
  | { kind: 'unavailable'; message: string }

export function clientRequestError(error: unknown): ClientRequestState {
  if (error instanceof RumboApiError) {
    if (error.status === 401) return { kind: 'unauthorized', message: 'Tu sesión terminó. Iniciá sesión para continuar.' }
    if (error.status === 403) return { kind: 'forbidden', message: 'No tenés permiso para ver este contenido.' }
    if (error.status === 400 || error.status === 422) return { kind: 'validation', message: error.message }
    if (error.status === 409) return { kind: 'conflict', message: 'Este cambio entró en conflicto. Actualizá los datos e intentá de nuevo.' }
  }
  if (error instanceof ApiTransportError && error.failure === 'invalid-response') return { kind: 'unavailable', message: 'El servidor devolvió datos inesperados. Intentá más tarde.' }
  return { kind: 'unavailable', message: 'No pudimos conectar con Rumbo. Revisá tu conexión e intentá de nuevo.' }
}
