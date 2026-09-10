# Project instructions

- Read and follow the root [CLAUDE.md](CLAUDE.md) before making changes.
- Follow the `CLAUDE.md` in the application being changed:
  [apps/api](apps/api/CLAUDE.md) for Laravel, [apps/web](apps/web/CLAUDE.md)
  for Next.js.
- Read the relevant document in [docs/architecture/](docs/architecture/) before
  touching the proxy, authentication or money. Those three are where a plausible
  change breaks something silently.
- Run `make check` before calling anything done, and do not report that it
  passed unless it did.
- `apps/web/AGENTS.md` is generated and re-added by `next dev`. Do not hand-edit
  it; commit it with your work rather than reverting it.
