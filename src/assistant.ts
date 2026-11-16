import type { FinancialSummary, FinancialState } from './finance'

export const assistantPrompts = [
  '¿En qué estoy gastando más?',
  '¿Cuánto dinero me queda?',
  '¿Cómo puedo ahorrar?',
  '¿Cómo va mi presupuesto?',
  '¿Cuánto me falta para mi meta?',
]

export function answerAssistant(question: string, state: FinancialState, summary: FinancialSummary) {
  const normalized = question.toLowerCase()
  const topCategory = Object.entries(summary.spentByCategory).sort((a, b) => b[1] - a[1])[0]
  const topGoal = state.goals.slice().sort((a, b) => (b.current / b.target) - (a.current / a.target))[0]

  if (normalized.includes('gastando más') || normalized.includes('gasto')) {
    return topCategory ? `En esta demostración, tu categoría con más gastos es ${topCategory[0].toLowerCase()}, con ${formatAssistantMoney(topCategory[1])} registrados.` : 'Todavía no hay gastos registrados para comparar.'
  }
  if (normalized.includes('queda') || normalized.includes('saldo')) {
    return `Tu saldo total de demostración es ${formatAssistantMoney(summary.balance)}. Después de separar ahorros, tenés ${formatAssistantMoney(summary.availableBalance)} disponibles para gastar.`
  }
  if (normalized.includes('ahorrar')) {
    const spendable = Math.max(summary.availableBalance, 0)
    if (!spendable) return 'En esta demostración no queda dinero disponible para separar. Revisá tus movimientos y aportes antes de planear otro ahorro.'
    const suggested = Math.min(spendable, Math.max(100, Math.round(spendable * .05)))
    return `Podés probar separando ${formatAssistantMoney(suggested)} este mes. Es una sugerencia educativa, no una recomendación de inversión.`
  }
  if (normalized.includes('presupuesto')) {
    const percentage = summary.budgetTotal ? Math.round((summary.budgetSpent / summary.budgetTotal) * 100) : 0
    return `Llevás ${percentage}% de tus límites de demostración utilizados. Podés revisar las categorías que se acerquen al 80%.`
  }
  if (normalized.includes('meta') || normalized.includes('falta')) {
    if (!topGoal) return 'Todavía no tenés metas configuradas en esta demostración.'
    return `A tu meta “${topGoal.name}” le faltan ${formatAssistantMoney(Math.max(topGoal.target - topGoal.current, 0))}. Vas en ${Math.round((topGoal.current / topGoal.target) * 100)}%.`
  }
  return 'Puedo ayudarte a revisar tus gastos, tu saldo, tus presupuestos o el avance de una meta. Probá una de las preguntas sugeridas.'
}

function formatAssistantMoney(value: number) {
  return new Intl.NumberFormat('es-HN', { style: 'currency', currency: 'HNL', maximumFractionDigits: 2 }).format(value).replace('HNL', 'L')
}
