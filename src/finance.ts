export type MovementKind = 'income' | 'expense'
export type Recurrence = 'one-time' | 'daily' | 'weekly' | 'biweekly' | 'monthly'
export type SavingsFrequency = 'daily' | 'weekly' | 'biweekly' | 'twice-monthly' | 'monthly'
export type ReminderFrequency = 'one-time' | 'weekly' | 'monthly'
export type ReminderCategory = 'Credit card' | 'Préstamo' | 'Alquiler' | 'Electricidad' | 'Agua' | 'Internet' | 'Teléfono' | 'Educación' | 'Suscripción' | 'Otra obligación'

export type Movement = { id: number; name: string; category: string; amount: number; kind: MovementKind; date: string; color: string; source?: string; recurrence?: Recurrence; planned?: boolean; goalId?: number; timestamp?: string; quickExpense?: boolean }
export type MovementTemplate = { id: number; name: string; kind: MovementKind; category: string; defaultAmount?: number; recurrence: Recurrence; notes?: string }
export type Budget = { id: string; category: string; limit: number; color: string }
export type SavingsGoal = { id: number; name: string; label: string; current: number; target: number; targetDate: string; targetLabel?: string; color: string; preferredFrequency?: SavingsFrequency; twiceMonthlyDays?: [number, number] }
export type SavingsContribution = { id: number; goalId: number; amount: number; date: string }
export type Reminder = { id: number; name: string; category: ReminderCategory; expectedAmount: number; dueDate: string; recurrence: ReminderFrequency; reminderDaysBefore: number; completed: boolean; notes: string; isCreditCard?: boolean; cardNickname?: string; bankName?: string; closingDate?: string; minimumPayment?: number }
export type NotificationKey = 'morning' | 'evening' | 'payments' | 'closing' | 'due' | 'savings' | 'budgets' | 'goals' | 'spending'
export type NotificationPreferences = Record<NotificationKey, boolean> & { morningTime: string; eveningTime: string; timezone: string }
export type FinancialNotification = { id: string; kind: NotificationKey; title: string; body: string; tone: 'info' | 'warning' | 'success'; dismissed?: boolean }
export type FinancialState = { openingBalance: number; movements: Movement[]; budgets: Budget[]; goals: SavingsGoal[]; contributions: SavingsContribution[]; reminders: Reminder[]; notificationPreferences: NotificationPreferences; templates?: MovementTemplate[] }

export const DEMO_TODAY = '2026-11-18'
export const starterMovements: Movement[] = [
  { id: 1, name: 'Pago quincenal', category: 'Salario', amount: 15000, kind: 'income', date: DEMO_TODAY, color: 'mint', source: 'Trabajo', recurrence: 'biweekly' },
  { id: 2, name: 'Supermercado La Colonia', category: 'Alimentación', amount: 1280, kind: 'expense', date: '2026-11-17', color: 'gold' },
  { id: 3, name: 'Uber / transporte', category: 'Transporte', amount: 420, kind: 'expense', date: '2026-11-17', color: 'sky' },
  { id: 4, name: 'Internet residencial', category: 'Hogar', amount: 850, kind: 'expense', date: '2026-11-12', color: 'rose' },
]
export const starterBudgets: Budget[] = [
  { id: 'food', category: 'Alimentación', limit: 5000, color: 'gold' }, { id: 'home', category: 'Hogar', limit: 4000, color: 'rose' }, { id: 'transport', category: 'Transporte', limit: 2500, color: 'sky' }, { id: 'leisure', category: 'Tiempo libre', limit: 1500, color: 'violet' },
]
export const starterGoals: SavingsGoal[] = [
  { id: 1, name: 'Fondo de emergencia', label: 'Prioridad alta', current: 6000, target: 10000, targetDate: '2027-05-31', targetLabel: 'Para mayo 2027', color: 'mint', preferredFrequency: 'monthly' },
  { id: 2, name: 'Viaje a Roatán', label: 'Plan personal', current: 2850, target: 8000, targetDate: '2027-08-31', targetLabel: 'Para agosto 2027', color: 'sky', preferredFrequency: 'twice-monthly' },
]
export const starterReminders: Reminder[] = [
  { id: 1, name: 'Internet residencial', category: 'Internet', expectedAmount: 850, dueDate: '2026-11-20', recurrence: 'monthly', reminderDaysBefore: 3, completed: false, notes: 'Revisar factura antes de pagar.' },
  { id: 2, name: 'Visa del hogar', category: 'Credit card', expectedAmount: 1800, dueDate: '2026-11-21', recurrence: 'monthly', reminderDaysBefore: 5, completed: false, notes: 'Pago esperado de la demo.', isCreditCard: true, cardNickname: 'Visa del hogar', bankName: 'Banco de demostración', closingDate: '2026-11-05', minimumPayment: 450 },
]
export const defaultNotificationPreferences: NotificationPreferences = { morning: true, evening: true, payments: true, closing: true, due: true, savings: true, budgets: true, goals: true, spending: false, morningTime: '07:00', eveningTime: '20:30', timezone: 'America/Tegucigalpa' }
export const initialFinancialState: FinancialState = { openingBalance: 300, movements: starterMovements, budgets: starterBudgets, goals: starterGoals, contributions: [], reminders: starterReminders, notificationPreferences: defaultNotificationPreferences, templates: [] }
export const emptyFinancialState: FinancialState = { openingBalance: 0, movements: [], budgets: [], goals: [], contributions: [], reminders: [], notificationPreferences: { ...defaultNotificationPreferences }, templates: [] }

export type FinancialSummary = { income: number; expenses: number; balance: number; availableBalance: number; allocatedSavings: number; spentByCategory: Record<string, number>; budgetTotal: number; budgetSpent: number; goalSaved: number; goalTarget: number }
const cents = (value: number) => Math.round((Number.isFinite(value) ? value : 0) * 100)
const moneyFromCents = (value: number) => value / 100
const formatMoney = (value: number) => new Intl.NumberFormat('es-HN', { style: 'currency', currency: 'HNL', maximumFractionDigits: 2 }).format(value).replace('HNL', 'L')

export function calculateSummary(state: FinancialState): FinancialSummary {
  const income = state.movements.filter((item) => item.kind === 'income' && !item.planned).reduce((sum, item) => sum + item.amount, 0)
  const expenses = state.movements.filter((item) => item.kind === 'expense' && !item.planned).reduce((sum, item) => sum + item.amount, 0)
  const spentByCategory = state.movements.filter((item) => item.kind === 'expense' && !item.planned).reduce<Record<string, number>>((result, item) => { result[item.category] = (result[item.category] || 0) + item.amount; return result }, {})
  const budgetSpent = state.budgets.reduce((sum, budget) => sum + (spentByCategory[budget.category] || 0), 0)
  const allocatedSavings = state.contributions.reduce((sum, item) => sum + item.amount, 0)
  const goalSaved = state.goals.reduce((sum, goal) => sum + goal.current, 0)
  const goalTarget = state.goals.reduce((sum, goal) => sum + goal.target, 0)
  const balance = state.openingBalance + income - expenses
  return { income, expenses, balance, availableBalance: balance - allocatedSavings, allocatedSavings, spentByCategory, budgetTotal: state.budgets.reduce((sum, budget) => sum + budget.limit, 0), budgetSpent, goalSaved, goalTarget }
}

export function calculateBudget(state: FinancialState, budget: Budget) {
  const spent = state.movements.filter((item) => item.kind === 'expense' && !item.planned && item.category === budget.category).reduce((sum, item) => sum + item.amount, 0)
  const remaining = budget.limit - spent; const ratio = budget.limit ? spent / budget.limit : 0
  return { spent, remaining, percent: Math.round(ratio * 100), status: remaining < 0 ? 'over' : ratio >= .8 ? 'warning' : 'ok' as 'over' | 'warning' | 'ok' }
}

function localDate(value: string) { const date = new Date(`${value}T12:00:00`); return Number.isNaN(date.getTime()) ? null : date }
function isoDate(date: Date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }
function lastDayOfMonth(year: number, month: number) { return new Date(year, month + 1, 0).getDate() }
export function formatDateForDisplay(value: string) { const date = localDate(value); return date ? new Intl.DateTimeFormat('es-HN', { day: 'numeric', month: 'short' }).format(date) : value }
export function formatGoalDate(goal: SavingsGoal) { return goal.targetLabel || (goal.targetDate ? `Para ${new Intl.DateTimeFormat('es-HN', { month: 'long', year: 'numeric' }).format(localDate(goal.targetDate) || new Date())}` : 'Sin fecha') }

function scheduledDates(start: Date, end: Date, frequency: SavingsFrequency, twiceMonthlyDays: [number, number] = [15, 0]) {
  if (end < start) return [] as Date[]
  const dates: Date[] = []
  if (frequency === 'daily' || frequency === 'weekly' || frequency === 'biweekly') {
    const step = frequency === 'daily' ? 1 : frequency === 'weekly' ? 7 : 14
    for (const cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + step)) dates.push(new Date(cursor))
    return dates
  }
  if (frequency === 'monthly') {
    for (let cursor = new Date(start.getFullYear(), start.getMonth(), 1); cursor <= end; cursor.setMonth(cursor.getMonth() + 1)) {
      const day = Math.min(start.getDate(), lastDayOfMonth(cursor.getFullYear(), cursor.getMonth())); const candidate = new Date(cursor.getFullYear(), cursor.getMonth(), day)
      if (candidate >= start && candidate <= end) dates.push(candidate)
    }
    return dates
  }
  for (let cursor = new Date(start.getFullYear(), start.getMonth(), 1); cursor <= end; cursor.setMonth(cursor.getMonth() + 1)) {
    const last = lastDayOfMonth(cursor.getFullYear(), cursor.getMonth())
    for (const configuredDay of twiceMonthlyDays) { const day = configuredDay === 0 ? last : Math.min(configuredDay, last); const candidate = new Date(cursor.getFullYear(), cursor.getMonth(), day); if (candidate >= start && candidate <= end && !dates.some((item) => isoDate(item) === isoDate(candidate))) dates.push(candidate) }
  }
  return dates.sort((a, b) => a.getTime() - b.getTime())
}

export type SavingsPlan = { frequency: SavingsFrequency; remaining: number; eligibleContributions: number; contribution: number; finalContribution: number; nextContributionDate: string | null; completed: boolean; message: string }
export function calculateSavingsPlan(goal: SavingsGoal, frequency: SavingsFrequency = goal.preferredFrequency || 'monthly', startDate = DEMO_TODAY): SavingsPlan {
  const remainingCents = Math.max(0, cents(goal.target) - cents(goal.current)); const start = localDate(startDate); const end = localDate(goal.targetDate)
  if (!remainingCents) return { frequency, remaining: 0, eligibleContributions: 0, contribution: 0, finalContribution: 0, nextContributionDate: null, completed: true, message: 'Esta meta ya está completa.' }
  if (!start || !end || end < start) return { frequency, remaining: moneyFromCents(remainingCents), eligibleContributions: 0, contribution: 0, finalContribution: 0, nextContributionDate: null, completed: false, message: 'La fecha límite debe ser hoy o una fecha futura para proyectar aportes.' }
  const dates = scheduledDates(start, end, frequency, goal.twiceMonthlyDays)
  if (!dates.length) return { frequency, remaining: moneyFromCents(remainingCents), eligibleContributions: 0, contribution: 0, finalContribution: 0, nextContributionDate: null, completed: false, message: 'No quedan fechas de aporte en este calendario. Elegí otra frecuencia o mové la fecha límite.' }
  const contributionCents = Math.ceil(remainingCents / dates.length); const finalCents = Math.max(0, remainingCents - contributionCents * (dates.length - 1))
  return { frequency, remaining: moneyFromCents(remainingCents), eligibleContributions: dates.length, contribution: moneyFromCents(contributionCents), finalContribution: moneyFromCents(finalCents), nextContributionDate: isoDate(dates[0]), completed: false, message: `Con ${dates.length} aporte${dates.length === 1 ? '' : 's'} programado${dates.length === 1 ? '' : 's'}, llegás a tu meta sin exceder el monto restante.` }
}

export function getReminderStatus(reminder: Reminder, today = DEMO_TODAY) {
  if (reminder.completed) return 'paid' as const
  const due = localDate(reminder.dueDate); const current = localDate(today); if (!due || !current) return 'upcoming' as const
  const days = Math.round((due.getTime() - current.getTime()) / 86400000)
  if (days < 0) return 'overdue' as const; if (days === 0) return 'today' as const; if (days <= reminder.reminderDaysBefore) return 'soon' as const; return 'upcoming' as const
}
export function daysUntil(value: string, today = DEMO_TODAY) { const date = localDate(value); const current = localDate(today); return date && current ? Math.round((date.getTime() - current.getTime()) / 86400000) : null }
export function getDailySummary(state: FinancialState, date = DEMO_TODAY) {
  const movements = state.movements.filter((item) => item.date === date && !item.planned); const contributions = state.contributions.filter((item) => item.date === date)
  const income = movements.filter((item) => item.kind === 'income').reduce((sum, item) => sum + item.amount, 0); const expenses = movements.filter((item) => item.kind === 'expense').reduce((sum, item) => sum + item.amount, 0); const savings = contributions.reduce((sum, item) => sum + item.amount, 0)
  return { income, expenses, savings, net: income - expenses - savings, movements, contributions }
}
export function getDailyReport(state: FinancialState, date = DEMO_TODAY) {
  const daily = getDailySummary(state, date)
  const expenses = daily.movements.filter((item) => item.kind === 'expense').sort((a, b) => (a.timestamp || '').localeCompare(b.timestamp || '') || a.id - b.id)
  const quickExpenses = expenses.filter((item) => item.quickExpense)
  return { date, movements: daily.movements, expenses, quickExpenses, expenseCount: expenses.length, totalExpenses: daily.expenses, totalQuickExpenses: quickExpenses.reduce((sum, item) => sum + item.amount, 0), income: daily.income, savings: daily.savings, net: daily.net }
}
export function getNotifications(state: FinancialState, today = DEMO_TODAY): FinancialNotification[] {
  const preferences = state.notificationPreferences; const notifications: FinancialNotification[] = []; const soonCount = state.reminders.filter((item) => getReminderStatus(item, today) === 'soon').length
  if (preferences.morning) notifications.push({ id: `morning-${today}`, kind: 'morning', title: 'Buenos días, este es tu rumbo de hoy', body: `Tenés ${soonCount} pago${soonCount === 1 ? '' : 's'} acercándose y ${state.goals.length} meta${state.goals.length === 1 ? '' : 's'} en seguimiento.`, tone: 'info' })
  if (preferences.evening) notifications.push({ id: `evening-${today}`, kind: 'evening', title: '¿Cómo te fue con tu dinero hoy?', body: 'Revisá si recibiste dinero, tuviste gastos o hiciste un aporte a una meta.', tone: 'info' })
  state.reminders.forEach((reminder) => { const status = getReminderStatus(reminder, today); if (status === 'overdue' && preferences.due) notifications.push({ id: `overdue-${reminder.id}-${today}`, kind: 'due', title: `${reminder.name} está pendiente`, body: `Venció el ${formatDateForDisplay(reminder.dueDate)}. Marcá el recordatorio o registrá el gasto por separado.`, tone: 'warning' }); if ((status === 'soon' || status === 'today') && preferences.payments) notifications.push({ id: `payment-${reminder.id}-${today}`, kind: 'payments', title: `${reminder.name} ${status === 'today' ? 'vence hoy' : 'se acerca'}`, body: `Monto esperado: ${formatMoney(reminder.expectedAmount)} · ${formatDateForDisplay(reminder.dueDate)}.`, tone: status === 'today' ? 'warning' : 'info' }) })
  if (preferences.savings) state.goals.forEach((goal) => { const plan = calculateSavingsPlan(goal); if (plan.nextContributionDate === today) notifications.push({ id: `saving-${goal.id}-${today}`, kind: 'savings', title: `Aporte programado: ${goal.name}`, body: `Hoy podrías aportar ${formatMoney(plan.contribution)}. Es una proyección, no un débito automático.`, tone: 'success' }) })
  return notifications
}
