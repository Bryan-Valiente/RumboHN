import { describe, expect, it } from 'vitest'
import {
  calculateBudget,
  calculateSavingsPlan,
  calculateSummary,
  defaultNotificationPreferences,
  formatDateForDisplay,
  getDailySummary,
  initialFinancialState,
  type FinancialState,
  type Movement,
  type SavingsGoal,
} from '../src/finance'

const makeState = (overrides: Partial<FinancialState> = {}): FinancialState => ({
  openingBalance: 300,
  movements: [],
  budgets: [],
  goals: [],
  contributions: [],
  reminders: [],
  notificationPreferences: { ...defaultNotificationPreferences },
  ...overrides,
})

const movement = (overrides: Partial<Movement> = {}): Movement => ({
  id: 1,
  name: 'Movimiento de prueba',
  category: 'General',
  amount: 100,
  kind: 'expense',
  date: '2026-11-18',
  color: 'mint',
  ...overrides,
})

const goal = (overrides: Partial<SavingsGoal> = {}): SavingsGoal => ({
  id: 1,
  name: 'Meta de prueba',
  label: 'Prueba',
  current: 100,
  target: 400,
  targetDate: '2026-11-20',
  color: 'mint',
  ...overrides,
})

describe('calculateSummary', () => {
  it('calculates the initial balance from the real demo state', () => {
    const summary = calculateSummary(initialFinancialState)

    expect(summary.income).toBe(15000)
    expect(summary.expenses).toBe(2550)
    expect(summary.balance).toBe(12750)
    expect(summary.availableBalance).toBe(12750)
  })

  it('includes an externally received income in the current balance', () => {
    const summary = calculateSummary(makeState({ movements: [movement({ kind: 'income', amount: 500, category: 'Trabajo' })] }))

    expect(summary.income).toBe(500)
    expect(summary.balance).toBe(800)
  })

  it('includes a registered expense and attributes it to its category', () => {
    const summary = calculateSummary(makeState({ movements: [movement({ amount: 125, category: 'Alimentación' })] }))

    expect(summary.expenses).toBe(125)
    expect(summary.balance).toBe(175)
    expect(summary.spentByCategory.Alimentación).toBe(125)
  })

  it('reflects an updated movement amount', () => {
    const before = calculateSummary(makeState({ movements: [movement({ amount: 100 })] }))
    const after = calculateSummary(makeState({ movements: [movement({ amount: 175 })] }))

    expect(before.expenses).toBe(100)
    expect(after.expenses).toBe(175)
    expect(after.balance).toBe(125)
  })

  it('reflects transaction removal without retaining the deleted amount', () => {
    const summary = calculateSummary(makeState({ movements: [movement({ id: 2, amount: 25 })] }))

    expect(summary.expenses).toBe(25)
    expect(summary.balance).toBe(275)
  })

  it('does not count planned income in current balance or available balance', () => {
    const summary = calculateSummary(makeState({ movements: [movement({ kind: 'income', amount: 1000, planned: true })] }))

    expect(summary.income).toBe(0)
    expect(summary.balance).toBe(300)
    expect(summary.availableBalance).toBe(300)
  })

  it('does not double-count savings contributions as expenses or reduce balance twice', () => {
    const summary = calculateSummary(makeState({
      movements: [movement({ kind: 'income', amount: 1000 })],
      contributions: [{ id: 1, goalId: 1, amount: 250, date: '2026-11-18' }],
    }))

    expect(summary.expenses).toBe(0)
    expect(summary.balance).toBe(1300)
    expect(summary.availableBalance).toBe(1050)
    expect(summary.allocatedSavings).toBe(250)
  })

  it('does not subtract unpaid reminders from available balance', () => {
    const summary = calculateSummary(makeState({
      reminders: [{ id: 1, name: 'Pago pendiente', category: 'Internet', expectedAmount: 200, dueDate: '2026-11-20', recurrence: 'monthly', reminderDaysBefore: 3, completed: false, notes: '' }],
    }))

    expect(summary.availableBalance).toBe(300)
  })
})

describe('calculateBudget', () => {
  it('calculates category spending and warning status', () => {
    const result = calculateBudget(makeState({ movements: [movement({ category: 'Hogar', amount: 80 })] }), { id: 'home', category: 'Hogar', limit: 100, color: 'rose' })

    expect(result.spent).toBe(80)
    expect(result.remaining).toBe(20)
    expect(result.percent).toBe(80)
    expect(result.status).toBe('warning')
  })
})

describe('calculateSavingsPlan', () => {
  it.each([
    ['daily', '2026-11-20', undefined, 3, '2026-11-18'],
    ['weekly', '2026-12-02', undefined, 3, '2026-11-18'],
    ['biweekly', '2026-12-16', undefined, 3, '2026-11-18'],
    ['twice-monthly', '2026-12-31', [15, 30] as [number, number], 3, '2026-11-30'],
    ['monthly', '2027-01-18', undefined, 2, '2026-12-18'],
  ] as const)('calculates %s contribution dates', (frequency, targetDate, twiceMonthlyDays, eligibleContributions, nextContributionDate) => {
    const plan = calculateSavingsPlan(goal({ targetDate, preferredFrequency: frequency, twiceMonthlyDays }), frequency, '2026-11-18')

    expect(plan.eligibleContributions).toBe(eligibleContributions)
    expect(plan.nextContributionDate).toBe(nextContributionDate)
  })

  it('adjusts the final contribution so the target is never exceeded', () => {
    const plan = calculateSavingsPlan(goal({ current: 0, target: 1, targetDate: '2026-11-20' }), 'daily', '2026-11-18')

    expect(plan.contribution).toBe(0.34)
    expect(plan.finalContribution).toBe(0.32)
    expect(plan.contribution * 2 + plan.finalContribution).toBe(1)
  })

  it('handles local calendar dates around month ends without creating an invalid date', () => {
    const plan = calculateSavingsPlan(goal({ current: 0, target: 300, targetDate: '2026-03-31' }), 'monthly', '2026-01-31')

    expect(plan.eligibleContributions).toBe(2)
    expect(plan.nextContributionDate).toBe('2026-02-28')
  })

  it('returns a safe result for invalid dates and non-finite current amounts', () => {
    const plan = calculateSavingsPlan(goal({ current: Number.NaN, targetDate: 'not-a-date' }), 'monthly', '2026-11-18')

    expect(plan.remaining).toBe(400)
    expect(plan.eligibleContributions).toBe(0)
    expect(plan.nextContributionDate).toBeNull()
    expect(formatDateForDisplay('not-a-date')).toBe('not-a-date')
  })
})

describe('getDailySummary', () => {
  it('summarizes today while excluding planned movements', () => {
    const state = makeState({
      movements: [
        movement({ kind: 'income', amount: 500, date: '2026-11-18' }),
        movement({ id: 2, amount: 100, date: '2026-11-18' }),
        movement({ id: 3, kind: 'income', amount: 900, planned: true, date: '2026-11-18' }),
      ],
      contributions: [{ id: 1, goalId: 1, amount: 50, date: '2026-11-18' }],
    })
    const summary = getDailySummary(state, '2026-11-18')

    expect(summary.income).toBe(500)
    expect(summary.expenses).toBe(100)
    expect(summary.savings).toBe(50)
    expect(summary.net).toBe(350)
    expect(summary.movements).toHaveLength(2)
  })
})
