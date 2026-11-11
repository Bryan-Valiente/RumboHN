# RUMBO GIT-003 source reconciliation record

## Evidence baseline

- Independent repository: `https://github.com/Bryan-Valiente/RumboHN`
- Official Site URL: `https://rumbo-hn-private-demo-20261110.bryanvaliente.chatgpt.site`
- Official Site project: `appgprj_6af3f1b0560c81919d5b6ea5620378db`
- Site source checkout: `visual-labs/rumbo-hn-sites-private-staging-20261110`
- Verified deployment source commit: `5f5c09330b895e45a152aee1d3226ba0a3bdac6d`
- Deployment commit parent: `e56dbb221d5cc54b5382d0f6a03419e91e5b5cec`
- Site metadata declares the static output directory as `dist`.

The deployment commit exists in the Site source checkout and contains the deployed `dist` artifacts. It is not reachable from the independent `RumboHN` repository or from the parent `Valiente-CEO` repository. No `.git` directory was copied between projects.

## Comparison result

The LOOP-003 source checkout and the independent repository were compared by SHA-256 for all source/configuration files while excluding `.git`, `node_modules`, and generated `dist` directories.

| Classification | Files or reason |
|---|---|
| Identical | `index.html`, `src/App.tsx`, `src/finance.ts`, `src/main.tsx`, `src/styles.css`, `src/vite-env.d.ts`, all TypeScript config files, `vite.config.ts` |
| Modified for GIT-002 | `package.json`, `package-lock.json`, `README.md` |
| Present only in GitHub | `.gitignore`, `.github/`, `docs/`, `tests/` |
| Missing in GitHub intentionally | `src/react-shim.d.ts`, removed during GIT-001 to resolve the React type conflict |
| Modified only by formatting | `src/assistant.ts` differs by a trailing newline |
| Conflicting application logic | None found before this focused date correction |
| Security-excluded content | No credentials, databases, deployment tokens, browser profiles, caches, or generated build directories were copied into the integration source |

The deployed `dist/index.html`, JavaScript bundle, and CSS bundle have identical SHA-256 hashes to the build produced from `RumboHN` before this integration correction. This proves artifact parity, not that the Site stores the original TypeScript source.

## Date inconsistency

`2026-11-18` is Wednesday in the declared Honduras timezone. The dashboard had a hardcoded `Lunes, 18 de noviembre de 2026` label, while the daily review already said `MIÉRCOLES 18 DE NOVIEMBRE`. The cause was stale hardcoded demonstration copy, not locale conversion or timezone arithmetic.

The isolated integration branch changes only that dashboard label to `Miércoles` and adds `tests/date-regression.test.ts`. The financial date helpers remain unchanged.

## GIT-002 preservation and merge order

The branch starts from `feature/bryan` at the GIT-002 commit, preserving Vitest, the 18 existing financial tests, CI, the pull request template, Owen onboarding, README instructions, and the lockfile.

Recommended sequence:

1. Review and merge GIT-002 PR #1 through normal protected-branch procedures.
2. Review the GIT-003 integration PR independently; its diff should then reduce to the source reconciliation record, the date regression test, and the one-line dashboard correction.
3. Merge only after the independent review and required CI checks pass.
4. Treat the resulting approved `main` SHA as the baseline for both developer branches.

No PR is auto-merged, no review is self-approved, and no personal branch is reset or force-pushed.

## Scope boundary

This branch does not deploy, update, delete, or change privacy for the official Site. It does not modify Hostinger, other repositories, or the parent repository. The application remains a fictitious, in-memory demo.
