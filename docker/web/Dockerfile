# Two images from one file.
#
# ```text
# base          pnpm, the workspace manifests, the installed dependency set
#   development next dev, with Compose mounting source over the top
#   production  the standalone build output, served by Node alone
# ```
#
# Compose names `target: development`; docker-compose.prod.yml names
# `target: production`.
FROM node:24-alpine AS base

# Pinned rather than taken from corepack's latest, so every build and every
# developer resolves the lockfile with the same version.
RUN npm install --global pnpm@9.15.9

ENV NEXT_TELEMETRY_DISABLED=1 \
    HOSTNAME=0.0.0.0 \
    PORT=3000

# Everything under /app belongs to the unprivileged user before any of it is
# populated. Compose mounts named volumes over node_modules and .next, and
# Docker initialises those volumes with the ownership the paths have in the
# image - root-owned here would leave Next unable to write its build cache.
RUN mkdir -p /app/apps/web && chown -R node:node /app

WORKDIR /app
USER node

# Manifests first, so the install layer caches independently of app source.
COPY --chown=node:node package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY --chown=node:node apps/web/package.json apps/web/package.json
RUN pnpm install --frozen-lockfile

COPY --chown=node:node apps/web apps/web

# Created here so that the named volume Compose mounts over it inherits this
# ownership. Docker creates a missing mountpoint as root, and Next cannot
# write its dev build cache into a root-owned directory.
RUN mkdir -p /app/apps/web/.next

WORKDIR /app/apps/web

EXPOSE 3000


# --- development ------------------------------------------------------------
#
# What Compose builds. Source and node_modules arrive as mounts, so nothing
# baked in here is the code that actually runs.
FROM base AS development

CMD ["pnpm", "dev", "--hostname", "0.0.0.0"]


# --- production -------------------------------------------------------------
#
# `output: "standalone"` in next.config.ts emits the server plus only the
# dependencies it actually reached for. The final stage copies that and
# nothing else: no pnpm, no lockfile, no source tree, no dev dependencies.
FROM base AS build

ENV NODE_ENV=production

# API_INTERNAL_URL is deliberately absent. Nothing here reads it: the address
# is looked up per request, not at build time, so one image runs against any
# environment. A value baked in at build would be the build machine's.
RUN pnpm build


FROM node:24-alpine AS production

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    HOSTNAME=0.0.0.0 \
    PORT=3000

WORKDIR /app
USER node

# The traced output is rooted at the workspace, not at apps/web, because
# outputFileTracingRoot says so - hence the nested apps/web paths. The static
# assets and public/ are not traced and have to come across separately.
COPY --from=build --chown=node:node /app/apps/web/.next/standalone ./
COPY --from=build --chown=node:node /app/apps/web/.next/static ./apps/web/.next/static
COPY --from=build --chown=node:node /app/apps/web/public ./apps/web/public

EXPOSE 3000

CMD ["node", "apps/web/server.js"]
