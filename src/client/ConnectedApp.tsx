import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ArrowDownLeft, ArrowUpRight, Eye, EyeOff, LogOut, Plus, RefreshCw, Trash2 } from 'lucide-react'
import { clientRequestError } from './apiState'
import { createRumboApi, normalizeHnl, RumboApiError, type HnlMovementWrite, type ServerMovement, type ServerProfile, type ServerSummary } from './rumboApi'

const api = createRumboApi()
const dateToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
const displayHnl = (value: string) => new Intl.NumberFormat('es-HN', { style: 'currency', currency: 'HNL', minimumFractionDigits: 2 }).format(Number(value)).replace('HNL', 'L')

type View = 'checking' | 'login' | 'register' | 'registration-pending' | 'ready' | 'unavailable'

export default function ConnectedApp() {
  const [view, setView] = useState<View>('checking')
  const [profile, setProfile] = useState<ServerProfile | null>(null)
  const [summary, setSummary] = useState<ServerSummary | null>(null)
  const [movements, setMovements] = useState<ServerMovement[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [editing, setEditing] = useState<ServerMovement | null>(null)
  const pendingCreate = useRef<{ fingerprint: string; key: string } | null>(null)

  const load = async () => {
    const [profileResult, summaryResult, movementResult] = await Promise.all([api.profile(), api.financialSummary(), api.movements()])
    setProfile(profileResult.data)
    setSummary(summaryResult.data)
    setMovements(movementResult.data)
    setCursor(movementResult.nextCursor)
    setView('ready')
    setError('')
  }

  const handleError = (cause: unknown) => {
    const state = clientRequestError(cause)
    setError('message' in state ? state.message : 'No pudimos completar la solicitud.')
    if (state.kind === 'unauthorized') { setPasswordVisible(false); setView('login'); setProfile(null); setSummary(null); setMovements([]) }
    else if (view === 'checking') setView('unavailable')
  }

  useEffect(() => {
    let active = true
    void api.session().then(async () => { if (active) await load() }).catch((cause: unknown) => {
      if (!active) return
      const state = clientRequestError(cause)
      if (state.kind === 'unauthorized') setView('login')
      else { setError('message' in state ? state.message : 'No pudimos conectar.'); setView('unavailable') }
    })
    return () => { active = false }
  }, [])

  const login = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    setBusy(true); setError('')
    try { await api.login(String(values.get('email') || ''), String(values.get('password') || '')); await load() }
    catch (cause) { if (cause instanceof RumboApiError && cause.code === 'AUTH_INVALID') setError(cause.message); else handleError(cause) }
    finally { setBusy(false) }
  }

  const register = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    setBusy(true); setError('')
    try {
      const result = await api.register(
        String(values.get('email') || '').trim().toLowerCase(),
        String(values.get('password') || ''),
        String(values.get('displayName') || '').trim(),
      )
      if (!['pending-verification', 'pending-admin-approval'].includes(result.data.status)) throw new Error('El servidor no confirmó la solicitud de cuenta.')
      form.reset()
      setPasswordVisible(false)
      setView('registration-pending')
    } catch (cause) {
      const state = clientRequestError(cause)
      setError('message' in state ? state.message : 'No pudimos registrar la solicitud. Intentá de nuevo.')
    } finally { setBusy(false) }
  }

  const logout = async () => {
    setBusy(true)
    try { await api.logout(); setPasswordVisible(false); setView('login'); setProfile(null); setSummary(null); setMovements([]); setCursor(null); setError('') }
    catch (cause) { handleError(cause) }
    finally { setBusy(false) }
  }

  const saveMovement = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    setBusy(true); setError('')
    try {
      const movement: HnlMovementWrite = {
        transactionType: values.get('transactionType') === 'income' ? 'income' : 'expense',
        name: String(values.get('name') || '').trim(),
        category: String(values.get('category') || '').trim(),
        amount: normalizeHnl(String(values.get('amount') || '')),
        currency: 'HNL',
        date: String(values.get('date') || ''),
        ...(!editing ? { status: 'confirmed' as const } : {}),
      }
      if (editing) {
        await api.updateMovement(editing.id, { transactionType: movement.transactionType, name: movement.name, category: movement.category, amount: movement.amount, date: movement.date })
      } else {
        const fingerprint = JSON.stringify(movement)
        if (pendingCreate.current?.fingerprint !== fingerprint) pendingCreate.current = { fingerprint, key: crypto.randomUUID() }
        await api.createMovement(movement, pendingCreate.current.key)
        pendingCreate.current = null
      }
      setEditing(null)
      form.reset()
      await load()
    } catch (cause) { handleError(cause) }
    finally { setBusy(false) }
  }

  const deleteMovement = async (movement: ServerMovement) => {
    if (!window.confirm(`¿Eliminar «${movement.name}»?`)) return
    setBusy(true); setError('')
    try { await api.deleteMovement(movement.id); await load() }
    catch (cause) { handleError(cause) }
    finally { setBusy(false) }
  }

  const more = async () => {
    if (!cursor) return
    setBusy(true); setError('')
    try { const result = await api.movements(cursor); setMovements((current) => [...current, ...result.data]); setCursor(result.nextCursor) }
    catch (cause) { handleError(cause) }
    finally { setBusy(false) }
  }

  return <div className="connected-app">
    <header className="connected-header"><div className="connected-brand"><span>R</span><strong>Rumbo HN</strong></div><span className="connected-mode">CUENTA CONECTADA</span>{view === 'ready' && <button className="connected-quiet" onClick={logout} disabled={busy}><LogOut size={17} /> Cerrar sesión</button>}</header>
    <main className="connected-main">
      {view === 'checking' && <section className="connected-panel" role="status">Comprobando tu sesión…</section>}
      {view === 'unavailable' && <section className="connected-panel"><h1>No pudimos conectar</h1><p>{error}</p><button className="connected-button" onClick={() => window.location.reload()}><RefreshCw size={17} /> Reintentar</button></section>}
      {view === 'login' && <section className="connected-panel connected-login"><span className="connected-eyebrow">ACCESO A CUENTA REAL</span><h1>Entrá a tu espacio</h1><p>Usá una cuenta que ya esté activa. Los datos locales de otro navegador no se importan automáticamente.</p><form onSubmit={login}><label>Correo electrónico<input name="email" type="email" autoComplete="username" required /></label><div className="connected-password-field"><label htmlFor="connected-password">Contraseña</label><span className="connected-password"><input id="connected-password" name="password" type={passwordVisible ? 'text' : 'password'} autoComplete="current-password" required /><button type="button" className="connected-password-toggle" aria-label={passwordVisible ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={passwordVisible} onClick={() => setPasswordVisible((visible) => !visible)}>{passwordVisible ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}</button></span></div>{error && <p className="connected-error" role="alert">{error}</p>}<button className="connected-button" type="submit" disabled={busy}>{busy ? 'Ingresando…' : 'Iniciar sesión'}</button></form><button className="connected-quiet connected-switch" type="button" onClick={() => { setError(''); setPasswordVisible(false); setView('register') }}>Solicitar una cuenta nueva</button></section>}
      {view === 'register' && <section className="connected-panel connected-login"><span className="connected-eyebrow">CUENTA EN EL SERVIDOR</span><h1>Solicitá tu cuenta</h1><p>Esta solicitud se envía a RUMBO en Hostinger. La cuenta quedará pendiente hasta que el administrador la active. Tu espacio local no se importa.</p><form onSubmit={register}><label>Tu nombre<input name="displayName" autoComplete="name" maxLength={120} required /></label><label>Correo electrónico<input name="email" type="email" autoComplete="email" maxLength={254} required /></label><div className="connected-password-field"><label htmlFor="connected-register-password">Contraseña</label><span className="connected-password"><input id="connected-register-password" name="password" type={passwordVisible ? 'text' : 'password'} autoComplete="new-password" minLength={12} maxLength={128} required /><button type="button" className="connected-password-toggle" aria-label={passwordVisible ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={passwordVisible} onClick={() => setPasswordVisible((visible) => !visible)}>{passwordVisible ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}</button></span></div>{error && <p className="connected-error" role="alert">{error}</p>}<button className="connected-button" type="submit" disabled={busy}>{busy ? 'Enviando…' : 'Solicitar cuenta'}</button></form><button className="connected-quiet connected-switch" type="button" onClick={() => { setError(''); setPasswordVisible(false); setView('login') }}>Ya tengo una cuenta activa</button></section>}
      {view === 'registration-pending' && <section className="connected-panel connected-login" role="status"><span className="connected-eyebrow">SOLICITUD RECIBIDA</span><h1>Cuenta pendiente</h1><p>RUMBO recibió tu solicitud. Podrás iniciar sesión cuando el administrador active la cuenta. No ingresés datos financieros hasta confirmar el acceso.</p><button className="connected-button" onClick={() => setView('login')}>Ir a iniciar sesión</button></section>}
      {view === 'ready' && <>
        <div className="connected-heading"><div><span className="connected-eyebrow">TU DINERO EN HNL</span><h1>Hola, {profile?.displayName}</h1>{profile?.email && <p>Cuenta: {profile.email}</p>}<p>Tu resumen viene de tu cuenta en el servidor.</p></div><button className="connected-quiet" onClick={() => void load().catch(handleError)} disabled={busy}><RefreshCw size={17} /> Actualizar</button></div>
        {error && <p className="connected-error" role="alert">{error}</p>}
        <div className="connected-metrics"><article><span>Saldo disponible</span><strong>{summary && displayHnl(summary.availableBalance)}</strong></article><article><span>Ingresos confirmados</span><strong>{summary && displayHnl(summary.confirmedIncome)}</strong></article><article><span>Gastos confirmados</span><strong>{summary && displayHnl(summary.confirmedExpenses)}</strong></article><article><span>Obligaciones pendientes</span><strong>{summary && displayHnl(summary.pendingObligations)}</strong></article></div>
        <div className="connected-grid"><section className="connected-panel"><h2>{editing ? 'Editar movimiento' : 'Nuevo movimiento'}</h2><p>El importe se registra en lempiras con dos decimales.</p><form key={editing?.id || 'new'} onSubmit={saveMovement}><label>Descripción<input name="name" defaultValue={editing?.name || ''} maxLength={160} required /></label><label>Categoría<input name="category" defaultValue={editing?.category || ''} maxLength={100} required /></label><div className="connected-form-row"><label>Importe HNL<input name="amount" inputMode="decimal" defaultValue={editing?.amount || ''} placeholder="125.50" required /></label><label>Fecha<input name="date" type="date" defaultValue={editing?.date || dateToday()} required /></label></div><label>Tipo<select name="transactionType" defaultValue={editing?.transactionType || 'expense'}><option value="expense">Gasto</option><option value="income">Ingreso</option></select></label><div className="connected-form-actions"><button className="connected-button" type="submit" disabled={busy}><Plus size={17} /> {busy ? 'Guardando…' : editing ? 'Guardar cambios' : 'Registrar'}</button>{editing && <button className="connected-quiet" type="button" onClick={() => setEditing(null)}>Cancelar</button>}</div></form></section>
        <section className="connected-panel"><h2>Movimientos</h2><p>{movements.length ? 'Registros de tu cuenta' : 'Todavía no hay movimientos.'}</p><div className="connected-list">{movements.map((movement) => <div className="connected-movement" key={movement.id}><span className={movement.transactionType === 'income' ? 'connected-symbol income' : 'connected-symbol'}>{movement.transactionType === 'income' ? <ArrowDownLeft size={17} /> : <ArrowUpRight size={17} />}</span><div><strong>{movement.name}</strong><small>{movement.category} · {movement.date}</small></div><b>{movement.transactionType === 'income' ? '+' : '−'}{displayHnl(movement.amount)}</b>{movement.sourceType !== 'reminder-payment' && <><button className="connected-quiet" onClick={() => setEditing(movement)} disabled={busy} aria-label={`Editar ${movement.name}`}>Editar</button><button className="connected-icon" onClick={() => void deleteMovement(movement)} disabled={busy} aria-label={`Eliminar ${movement.name}`}><Trash2 size={16} /></button></>}</div>)}</div>{cursor && <button className="connected-quiet" onClick={more} disabled={busy}>Cargar más</button>}</section></div>
      </>}
    </main>
  </div>
}
