# Mongez — Agent Rules

## Git Safety — Never Push Unless Green

- NEVER `git push` unless BOTH are fully green on PHP 8.5 (system `php`):
  - `composer phpstan` — 0 errors.
  - `composer test` (`php vendor/bin/phpunit`) — all tests pass, no failures or errors.
- If either is red, fix first, re-run, and only then consider pushing.
- Only push when the user explicitly asks to push (e.g. "push", "push to origin").
  Do not push after commit, after merge, or as part of any workflow unless
  directly instructed.
- NEVER rewrite pushed history (`reset --hard`, `commit --amend`, `rebase -i`,
  `push --force`). Undo pushed commits with `git revert`.

## Runtime

- System `php` is 8.5; CI (`.github/workflows/tests.yml`) runs PHP 8.5 only.
- `composer.json` requires `php ^8.4 || ^8.5`.
