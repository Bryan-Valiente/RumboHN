import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('Rumbo demo calendar labels', () => {
  it('keeps 2026-11-18 aligned with the Wednesday dashboard label', () => {
    const date = new Date('2026-11-18T12:00:00')
    const weekday = new Intl.DateTimeFormat('es-HN', { weekday: 'long', timeZone: 'America/Tegucigalpa' }).format(date)
    const appSource = readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')

    expect(weekday).toBe('miércoles')
    expect(appSource).toContain('Miércoles, 18 de noviembre de 2026')
    expect(appSource).not.toContain('Lunes, 18 de noviembre de 2026')
  })
})
