import { useState } from 'react'
import type { FormEvent } from 'react'
import { CalendarClock, CreditCard, X } from 'lucide-react'
import { DEMO_TODAY } from './finance'
import type { Reminder, ReminderCategory, ReminderFrequency } from './finance'

const categories: ReminderCategory[] = ['Electricidad', 'Internet', 'Agua', 'Alquiler', 'Supermercado', 'Transporte', 'Préstamo', 'Credit card', 'Suscripción', 'Otra obligación']

type Props = { reminder: Reminder | null; close: () => void; saveReminder: (reminder: Reminder) => void }

export function PersonalReminderModal({ reminder, close, saveReminder }: Props) {
  const [category, setCategory] = useState<ReminderCategory>(reminder?.category || 'Internet')
  const isCard = category === 'Credit card'

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const name = String(form.get('name') || '').trim()
    const amount = Number(form.get('amount'))
    if (!name || !Number.isFinite(amount) || amount <= 0) return
    const recurrence = String(form.get('recurrence') || 'monthly') as ReminderFrequency
    const dayA = Number(form.get('twiceMonthlyDayA') || 15)
    const dayB = Number(form.get('twiceMonthlyDayB') || 30)
    saveReminder({
      id: reminder?.id ?? Date.now(),
      name,
      category,
      expectedAmount: amount,
      firstPaymentDate: String(form.get('firstPaymentDate') || form.get('dueDate') || DEMO_TODAY),
      dueDate: String(form.get('dueDate') || DEMO_TODAY),
      recurrence,
      twiceMonthlyDays: recurrence === 'twice-monthly' ? [dayA, dayB] : undefined,
      customIntervalDays: recurrence === 'custom' ? Math.max(1, Number(form.get('customIntervalDays') || 30)) : undefined,
      reminderDaysBefore: Number(form.get('reminderDaysBefore') || 3),
      completed: reminder?.completed ?? false,
      paidAt: reminder?.paidAt,
      paidMovementId: reminder?.paidMovementId,
      generatedNextId: reminder?.generatedNextId,
      notes: String(form.get('notes') || ''),
      isCreditCard: isCard,
      cardNickname: isCard ? name : undefined,
      maskedIdentifier: isCard ? String(form.get('maskedIdentifier') || '') : undefined,
      bankName: isCard ? String(form.get('bankName') || '') : undefined,
      closingDate: isCard ? String(form.get('closingDate') || '') : undefined,
      statementDate: isCard ? String(form.get('statementDate') || '') : undefined,
      minimumPayment: isCard ? Number(form.get('minimumPayment') || 0) : undefined,
    })
  }

  return <div className="modal-backdrop"><div className="small-modal reminder-modal"><button className="close-button" onClick={close} aria-label="Cerrar"><X size={19} /></button><span className="eyebrow">{isCard ? 'CONTROL DE TARJETA' : 'COMPROMISO FICTICIO'}</span><h2>{isCard ? 'Registrar tarjeta' : reminder ? 'Editar recordatorio' : 'Agregar recordatorio'}</h2><p>Guardá pagos de demostración. RUMBO no cobra, no conecta bancos y no solicita números completos ni CVV.</p><form onSubmit={submit}><label>Nombre o apodo<input name="name" defaultValue={reminder?.name || ''} placeholder={isCard ? 'Ej. Visa del hogar' : 'Ej. Internet residencial'} required /></label><div className="form-grid"><label>Categoría<select name="category" value={category} onChange={(event) => setCategory(event.target.value as ReminderCategory)}>{categories.map((item) => <option key={item}>{item}</option>)}</select></label><label>Monto esperado en HNL<input name="amount" type="number" min="0.01" step="0.01" defaultValue={reminder?.expectedAmount || ''} placeholder="1,500.00" required /></label></div><div className="form-grid"><label>Primera fecha aplicable<input name="firstPaymentDate" type="date" defaultValue={reminder?.firstPaymentDate || reminder?.dueDate || DEMO_TODAY} required /></label><label>Fecha de pago<input name="dueDate" type="date" defaultValue={reminder?.dueDate || DEMO_TODAY} required /></label></div><div className="form-grid"><label>Frecuencia<select name="recurrence" defaultValue={reminder?.recurrence || 'monthly'}><option value="one-time">Una vez</option><option value="weekly">Semanal</option><option value="every-15-days">Cada 15 días</option><option value="twice-monthly">Dos fechas al mes</option><option value="monthly">Mensual</option><option value="custom">Personalizada</option></select></label><label>Avisar con<select name="reminderDaysBefore" defaultValue={String(reminder?.reminderDaysBefore ?? 3)}><option value="0">El día de pago</option><option value="1">1 día antes</option><option value="3">3 días antes</option><option value="5">5 días antes</option><option value="7">7 días antes</option></select></label></div><div className="form-grid"><label>Día mensual A<input name="twiceMonthlyDayA" type="number" min="1" max="31" defaultValue={reminder?.twiceMonthlyDays?.[0] || 15} /></label><label>Día mensual B<input name="twiceMonthlyDayB" type="number" min="1" max="31" defaultValue={reminder?.twiceMonthlyDays?.[1] || 30} /></label></div><label>Intervalo personalizado en días<input name="customIntervalDays" type="number" min="1" max="365" defaultValue={reminder?.customIntervalDays || 30} /></label>{isCard && <div className="card-form-fields"><div className="card-warning"><CreditCard size={16} /><span>Solo apodo, identificador enmascarado opcional y monto manual. Nunca guardés número completo, CVV ni credenciales.</span></div><label>Apodo de tarjeta<input name="cardNickname" defaultValue={reminder?.cardNickname || reminder?.name || ''} placeholder="Visa del hogar" /></label><label>Identificador enmascarado opcional<input name="maskedIdentifier" defaultValue={reminder?.maskedIdentifier || ''} placeholder="•••• 1234" maxLength={10} /></label><div className="form-grid"><label>Fecha de corte<input name="closingDate" type="date" defaultValue={reminder?.closingDate || ''} /></label><label>Fecha de estado<input name="statementDate" type="date" defaultValue={reminder?.statementDate || ''} /></label></div><label>Pago mínimo opcional<input name="minimumPayment" type="number" min="0" step="0.01" defaultValue={reminder?.minimumPayment || ''} placeholder="450.00" /></label></div>}<label>Notas opcionales<textarea name="notes" rows={2} defaultValue={reminder?.notes || ''} placeholder="Ej. Emitido el día 11; vence el 30…" /></label><button className="button button-dark full-width" type="submit">{reminder ? 'Guardar cambios' : 'Guardar recordatorio'} <CalendarClock size={17} /></button></form></div></div>
}
