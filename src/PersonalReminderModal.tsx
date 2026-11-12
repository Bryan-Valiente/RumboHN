import { useState } from 'react'
import type { FormEvent } from 'react'
import { CalendarClock, X } from 'lucide-react'
import { DEMO_TODAY } from './finance'
import type { Reminder, ReminderCategory, ReminderFrequency } from './finance'

const categories: ReminderCategory[] = ['Credit card', 'Préstamo', 'Alquiler', 'Electricidad', 'Agua', 'Internet', 'Teléfono', 'Educación', 'Suscripción', 'Otra obligación']

type Props = {
  reminder: Reminder | null
  close: () => void
  saveReminder: (reminder: Reminder) => void
}

export function PersonalReminderModal({ reminder, close, saveReminder }: Props) {
  const [category, setCategory] = useState<ReminderCategory>(reminder?.category || 'Internet')
  const isCard = category === 'Credit card'

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const name = String(form.get('name') || '').trim()
    const amount = Number(form.get('amount'))
    if (!name || !Number.isFinite(amount) || amount <= 0) return

    saveReminder({
      id: reminder?.id ?? Date.now(),
      name,
      category,
      expectedAmount: amount,
      dueDate: String(form.get('dueDate') || DEMO_TODAY),
      recurrence: String(form.get('recurrence') || 'monthly') as ReminderFrequency,
      reminderDaysBefore: Number(form.get('reminderDaysBefore') || 3),
      completed: reminder?.completed ?? false,
      notes: String(form.get('notes') || ''),
      isCreditCard: isCard,
      cardNickname: isCard ? name : undefined,
      bankName: isCard ? String(form.get('bankName') || '') : undefined,
      closingDate: isCard ? String(form.get('closingDate') || '') : undefined,
      minimumPayment: isCard ? Number(form.get('minimumPayment') || 0) : undefined,
    })
  }

  return <div className="modal-backdrop"><div className="small-modal"><button className="close-button" onClick={close} aria-label="Cerrar"><X size={19} /></button><span className="eyebrow">COMPROMISO FICTICIO</span><h2>{isCard ? 'Registrar tarjeta' : reminder ? 'Editar recordatorio' : 'Agregar recordatorio'}</h2><p>Guardá solo datos de demostración; nunca números completos, CVV, contraseñas o credenciales bancarias.</p><form onSubmit={submit}><label>Nombre o apodo<input name="name" defaultValue={reminder?.name || ''} placeholder={isCard ? 'Ej. Visa del hogar' : 'Ej. Internet residencial'} required /></label><div className="form-grid"><label>Categoría<select name="category" value={category} onChange={(event) => setCategory(event.target.value as ReminderCategory)}>{categories.map((item) => <option key={item}>{item}</option>)}</select></label><label>Monto esperado<input name="amount" type="number" min="0.01" step="0.01" defaultValue={reminder?.expectedAmount || ''} placeholder="1,500.00" required /></label></div><div className="form-grid"><label>Fecha de pago<input name="dueDate" type="date" defaultValue={reminder?.dueDate || DEMO_TODAY} required /></label><label>Avisar con<select name="reminderDaysBefore" defaultValue={String(reminder?.reminderDaysBefore || 3)}><option value="1">1 día</option><option value="3">3 días</option><option value="5">5 días</option><option value="7">7 días</option></select></label></div><label>Recurrencia<select name="recurrence" defaultValue={reminder?.recurrence || 'monthly'}><option value="one-time">Una vez</option><option value="weekly">Semanal</option><option value="monthly">Mensual</option></select></label>{isCard && <div className="card-form-fields"><label>Banco opcional<input name="bankName" defaultValue={reminder?.bankName || ''} placeholder="Solo nombre, sin credenciales" /></label><div className="form-grid"><label>Fecha de corte<input name="closingDate" type="date" defaultValue={reminder?.closingDate || ''} /></label><label>Pago mínimo opcional<input name="minimumPayment" type="number" min="0" step="0.01" defaultValue={reminder?.minimumPayment || ''} placeholder="450.00" /></label></div></div>}<label>Notas opcionales<textarea name="notes" rows={2} defaultValue={reminder?.notes || ''} placeholder="Una nota para tu demo…" /></label><button className="button button-dark full-width" type="submit">{reminder ? 'Guardar cambios' : 'Guardar recordatorio'} <CalendarClock size={17} /></button></form></div></div>
}
