# syntax=docker/dockerfile:1.7
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · motor predictivo (Python 3.12, FastAPI) sobre Ubuntu 24.04 LTS.
# Solo red interna: no publicar puertos.
#
#   docker build -t predictily/engine .
#
# Ubuntu 24.04 trae Python 3.12 y libgomp1 (OpenMP, requerido por LightGBM) empaquetados.
#
# Verificación detrás de un proxy TLS (opcional, NO necesario en producción):
#   docker build --network host --build-arg https_proxy=http://127.0.0.1:PORT \
#     --build-arg APT_HTTPS=true --secret id=extra_ca,src=/ruta/ca-bundle.crt -t predictily/engine .
# ─────────────────────────────────────────────────────────────────────────────
FROM ubuntu:24.04 AS base
ARG APT_HTTPS=false
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    VIRTUAL_ENV=/opt/venv \
    PATH=/opt/venv/bin:$PATH
RUN --mount=type=secret,id=extra_ca,required=false \
    set -eux; \
    if [ "$APT_HTTPS" = "true" ]; then sed -i 's|http://|https://|g' /etc/apt/sources.list.d/ubuntu.sources; fi; \
    if [ -f /run/secrets/extra_ca ]; then \
      install -m 0644 /run/secrets/extra_ca /tmp/extra-ca.crt; \
      echo 'Acquire::https::CAInfo "/tmp/extra-ca.crt";' > /etc/apt/apt.conf.d/99extra-ca; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates tzdata python3.12 python3.12-venv libgomp1; \
    if [ -f /run/secrets/extra_ca ]; then \
      cp /run/secrets/extra_ca /usr/local/share/ca-certificates/extra-ca.crt && update-ca-certificates; \
    fi; \
    rm -f /etc/apt/apt.conf.d/99extra-ca /tmp/extra-ca.crt; \
    apt-get clean; rm -rf /var/lib/apt/lists/*; \
    python3.12 -m venv /opt/venv

# ── Instalación del paquete y dependencias en el venv ────────────────────────
FROM base AS builder
WORKDIR /src
COPY . .
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/root/.cache/pip \
    if [ -f /run/secrets/extra_ca ]; then export PIP_CERT=/run/secrets/extra_ca; fi; \
    pip install --upgrade pip \
 && pip install .

# ── Imagen final ─────────────────────────────────────────────────────────────
FROM base AS runtime
LABEL org.opencontainers.image.title="predictily-engine" \
      org.opencontainers.image.description="Predictily · motor de pronóstico interno (FastAPI)"
COPY --from=builder /opt/venv /opt/venv
RUN useradd --system --uid 10001 --create-home --home-dir /home/engine engine \
 && mkdir -p /data/artifacts /tmp/numba-cache \
 && chown -R engine:engine /data /tmp/numba-cache
ENV ENGINE_ARTIFACTS_DIR=/data/artifacts \
    NUMBA_CACHE_DIR=/tmp/numba-cache \
    MPLCONFIGDIR=/tmp \
    ENGINE_WORKERS=2
USER engine
WORKDIR /home/engine
EXPOSE 8100
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8100/health', timeout=4).status == 200 else 1)"
CMD ["sh", "-c", "exec uvicorn predictily_engine.api.main:app --host 0.0.0.0 --port 8100 --workers ${ENGINE_WORKERS} --timeout-keep-alive 75 --no-server-header"]
