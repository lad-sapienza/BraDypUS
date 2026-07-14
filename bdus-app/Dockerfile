# ── Stage 1: build the Vue SPA ────────────────────────────────────────────────
FROM node:24-alpine AS builder

WORKDIR /app

# Install dependencies first (layer-cached until package files change)
COPY package*.json ./
RUN npm ci

# Copy source and build
COPY . .

# VITE_API_BASE is intentionally left empty here: the Nginx proxy (stage 2)
# forwards /api/, /index.php, /projects/, /cache/ to the backend, so the
# browser uses relative URLs and no explicit base is required.
# Pass --build-arg VITE_API_BASE=https://api.example.com only when the
# backend is on a *different origin* and you are NOT using the nginx proxy.
ARG VITE_API_BASE=
ENV VITE_API_BASE=${VITE_API_BASE}

RUN npm run build


# ── Stage 2: serve with Nginx ─────────────────────────────────────────────────
FROM nginx:alpine AS production

# The official nginx:alpine image processes *.template files in
# /etc/nginx/templates/ with envsubst before starting, so we can use
# ${API_PROXY_TARGET} inside the config without a custom entrypoint.
COPY nginx.conf.template /etc/nginx/templates/default.conf.template

# Copy the compiled SPA from the builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# API_PROXY_TARGET is resolved at container start (envsubst), not at build time.
# Default points to the "api" service name used in docker-compose.prod.yml.
ENV API_PROXY_TARGET=http://api:80

EXPOSE 80
