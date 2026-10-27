# syntax=docker/dockerfile:1.7
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · SPA Vue 3 compilada y servida por nginx (servicio "web").
# nginx también hace proxy de /api, /sanctum y /up hacia php-fpm (servicio backend).
#
# La configuración de nginx vive en infra/nginx y se inyecta como contexto adicional:
#   docker build --build-context infra=../infra -t predictily/web .
# (docker compose lo hace automáticamente con additional_contexts).
# ─────────────────────────────────────────────────────────────────────────────
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/root/.npm \
    if [ -f /run/secrets/extra_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/extra_ca; fi; \
    npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM nginx:1.27-alpine AS runtime
LABEL org.opencontainers.image.title="predictily-web" \
      org.opencontainers.image.description="Predictily · SPA + proxy inverso hacia la API"
RUN rm -f /etc/nginx/conf.d/default.conf && mkdir -p /etc/nginx/snippets
COPY --from=infra nginx/default.conf /etc/nginx/conf.d/predictily.conf
COPY --from=infra nginx/snippets/ /etc/nginx/snippets/
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1
