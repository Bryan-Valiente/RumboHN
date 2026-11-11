# RUMBO HN onboarding guide for Owen

This guide is for the collaborator account `owenninversioaprendizaje-art`.

## 1. Accept the invitation

Open the GitHub invitation email or repository notifications and accept access to `Bryan-Valiente/RumboHN`. The requested role is **Write**. Never send a password, personal access token, one-time code, or other credential to anyone.

## 2. Connect your own GitHub account to Codex

In Codex, sign in or connect GitHub using your own account, `owenninversioaprendizaje-art`. Complete any authorization or verification screen directly in the browser. Do not share credentials or verification codes in chat.

## 3. Verify the local tools

In PowerShell, confirm that Git, Node.js, and npm are available:

```powershell
git --version
node --version
npm --version
```

Use Node.js LTS. This repository's CI currently uses Node.js 22.x.

## 4. Clone and select your branch

```powershell
git clone https://github.com/Bryan-Valiente/RumboHN.git
cd RumboHN
git fetch origin
git switch --track origin/feature/owen
```

If the branch already exists locally, use `git switch feature/owen` instead.

## 5. Install, run, test, and build

```powershell
npm ci
npm run dev
```

Open the local URL printed by Vite. Stop the development server with `Ctrl+C`. Before opening a pull request, run:

```powershell
npm run test:run
npm run build
```

The unit tests use Vitest and cover the existing financial calculations, savings frequencies, date handling, and invalid-input boundaries.

## 6. Daily development workflow

1. Start from `feature/owen` and keep each change focused.
2. Review the current state before editing: `git status` and `git pull --ff-only origin feature/owen`.
3. Make and review only the requested change.
4. Check the staged patch before committing:

   ```powershell
   git diff --check
   git diff --stat
   ```

5. Commit with a clear message and push your branch:

   ```powershell
   git add <files-you-changed>
   git commit -m "describe the focused change"
   git push -u origin feature/owen
   ```

6. Open a pull request from `feature/owen` into `main` and complete the repository pull request template. CI runs tests and the production build automatically.

Do not push directly to `main`. Do not use `git reset --hard`, force-push, or discard someone else's uncommitted work. If the branch has diverged or a conflict is unclear, stop and coordinate before rewriting history.

## 7. Sync with `main`

When you need the latest protected branch:

```powershell
git fetch origin
git switch feature/owen
git pull --ff-only origin feature/owen
git merge origin/main
```

Resolve conflicts carefully, run the tests and build again, then push the merge commit if the result is correct. Pull requests still require the repository's configured review and status checks.

## Scope and collaboration boundaries

Rumbo HN is a demo-oriented application. Use synthetic data only and do not add real credentials, customer data, payment data, or production integrations. This onboarding work does not modify ChatGPT Sites, Hostinger, or other Valiente CEO repositories. Application feature development belongs to a separately authorized task.
