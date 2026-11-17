import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import ConnectedApp from './client/ConnectedApp'
import './styles.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>{import.meta.env.VITE_RUMBO_CLIENT_MODE === 'api' ? <ConnectedApp /> : <App />}</StrictMode>,
)

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register(`${import.meta.env.BASE_URL}sw.js`).catch((error: unknown) => {
      console.warn('No se pudo habilitar el uso sin conexión de Rumbo.', error)
    })
  })
}
