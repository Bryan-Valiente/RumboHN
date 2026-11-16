import { describe, expect, it } from 'vitest'
import { answerAssistant } from '../src/assistant'
import { calculateSummary, initialFinancialState } from '../src/finance'

describe('assistant balance explanation', () => {
  it('distinguishes total balance from spendable money after a goal contribution', () => {
    const state = {
      ...initialFinancialState,
      contributions: [{ id: 1, goalId: 1, amount: 1000, date: '2026-11-18' }],
    }
    const summary = calculateSummary(state)
    const answer = answerAssistant('¿Cuánto saldo me queda?', state, summary)

    expect(summary.balance).toBe(12750)
    expect(summary.availableBalance).toBe(11750)
    expect(answer).toContain('12,750')
    expect(answer).toContain('11,750')
    expect(answer).toContain('disponibles para gastar')
    expect(answerAssistant('¿Cómo puedo ahorrar?', state, summary)).toContain('588')
  })

  it('does not suggest another contribution when no spendable money remains', () => {
    const state = {
      ...initialFinancialState,
      contributions: [{ id: 1, goalId: 1, amount: 12750, date: '2026-11-18' }],
    }
    const summary = calculateSummary(state)

    expect(answerAssistant('¿Cómo puedo ahorrar?', state, summary)).toContain('no queda dinero disponible')
  })
})
