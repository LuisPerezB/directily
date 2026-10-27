# Despliegue de Predictily en un VPS

Guía para un servidor único, económico y sin GPU (4 vCPU, 8–16 GB RAM, 40+ GB SSD;
Ubuntu 24.04 LTS). Arquitectura y decisiones: `docs/02-arquitectura.md`. Seguridad:
`docs/07-seguridad-multitenant.md`.

## 1. Preparar el servidor

```bash
# Usuario sin privilegios con acceso por llave SSH
adduser --disabled-password predictily && usermod -aG sudo predictily
# /etc/ssh/sshd_config: PasswordAuthentication no · PermitRootLogin no  → systemctl reload ssh

# Firewall: solo SSH, HTTP y HTTPS
ufw default deny incoming && ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable

# Parches de seguridad automáticos
apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades

# Docker Engine + Compose v2 (repositorio oficial de Docker)
curl -fsSL https://get.docker.com | sh && usermod -aG docker predictily
```

Recomendado: disco cifrado (opción del proveedor o LUKS) y swap de 2–4 GB.

## 2. DNS y dominio

1. Verificar disponibilidad y titularidad del dominio (p. ej. `predictily.com`) antes del lanzamiento.
2. Crear registro `A` (y `AAAA` si hay IPv6) de `app.su-dominio.com` → IP del VPS.

## 3. Configurar y levantar

```bash
sudo mkdir -p /opt/predictily && sudo chown predictily: /opt/predictily
git clone <repositorio> /opt/predictily && cd /opt/predictily
make setup                       # genera .env con secretos aleatorios
chmod 600 .env
```

Editar `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.su-dominio.com
PREDICTILY_DOMAIN=app.su-dominio.com
ACME_EMAIL=operaciones@su-dominio.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.su-dominio.com
POSTGRES_EXTRA_DATABASES=          # sin bases de prueba en producción
SUPERADMIN_EMAIL=...               # opcional
SUPERADMIN_PASSWORD=...            # opcional, cámbiela tras el primer ingreso
MAIL_MAILER=smtp                   # y MAIL_HOST/PORT/USERNAME/PASSWORD
```

**Guarde una copia de `APP_KEY` fuera del servidor**: sin ella no se pueden descifrar los datos
cifrados (nombres de clientes, credenciales de conectores).

```bash
make up-prod                     # Caddy (TLS) + web + backend + colas + scheduler + engine + BD
make seed                        # planes y datos base (equivale a: docker compose exec backend php artisan db:seed --force)
docker compose ps                # todos "running"/"healthy"
curl -I https://app.su-dominio.com/up
```

Caddy obtiene y renueva automáticamente el certificado de Let's Encrypt y añade HSTS. El único
servicio con puertos públicos es `caddy` (80/443); `web` queda en la red interna `edge`, y
`engine`, `postgres` y `redis` en la red `internal` (sin salida a Internet).

### Alternativa: nginx del host + certbot
Si prefiere no usar Caddy: deje `web` publicado en `127.0.0.1:8080` (cambie `ports` a
`"127.0.0.1:8080:80"`), configure un `server` TLS en el nginx del host con
`proxy_pass http://127.0.0.1:8080;`, `proxy_set_header X-Forwarded-Proto https;`,
`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`, `client_max_body_size 25m;`,
HSTS, y obtenga el certificado con `certbot --nginx -d app.su-dominio.com`.

## 4. Respaldos

```bash
crontab -e
# Diario 03:00: respaldo de PostgreSQL con rotación (BACKUP_RETENTION_DAYS)
0 3 * * * cd /opt/predictily && infra/scripts/backup.sh >> /var/log/predictily-backup.log 2>&1
# Mensual: prueba de restauración en base temporal
0 5 1 * * cd /opt/predictily && make restore-verify >> /var/log/predictily-backup.log 2>&1
```

- Copie `backups/` fuera del servidor (rclone/s3cmd a almacenamiento de objetos) y, si es posible,
  cifre con GPG (`BACKUP_GPG_RECIPIENT`).
- Respalde también los volúmenes `predictily_backend-storage` (archivos importados) y, opcionalmente,
  `predictily_engine-artifacts` (los modelos pueden regenerarse con una nueva ejecución):
  `docker run --rm -v predictily_backend-storage:/v -v $PWD/backups:/b ubuntu:24.04 tar czf /b/storage_$(date +%F).tgz -C /v .`
- Objetivos: **RPO ≤ 24 h, RTO ≤ 4 h**.

Restaurar (reemplaza la base; detener antes servicios que escriben):

```bash
docker compose stop backend queue queue-forecasting scheduler engine
infra/scripts/restore.sh backups/predictily_AAAAMMDDTHHMMSSZ.dump
docker compose start backend queue queue-forecasting scheduler engine
```

## 5. Actualizar a una nueva versión

```bash
cd /opt/predictily
infra/scripts/backup.sh                       # respaldo previo obligatorio
git pull
make up-prod                                  # reconstruye imágenes; backend migra al arrancar
docker compose logs --tail=100 backend queue-forecasting engine
```

Las migraciones son versionadas; revise en el changelog si alguna requiere ventana de
mantenimiento. Para revertir: volver al commit anterior y restaurar el respaldo si hubo
migraciones destructivas.

## 6. Monitoreo mínimo

- Salud: `https://app.su-dominio.com/up` (Laravel) y `docker compose ps` (healthchecks de
  postgres, redis, backend, engine y web).
- Trabajos: estado y duración en la tabla `training_jobs` / pantalla de pronósticos; trabajos
  fallidos en `failed_jobs` (`docker compose exec backend php artisan queue:failed`).
- Recursos: alertas de disco (> 80 %), memoria y CPU con el monitor del proveedor o node-exporter.
- Logs: `docker compose logs`; configure rotación en `/etc/docker/daemon.json`
  (`"log-driver": "json-file", "log-opts": {"max-size": "20m", "max-file": "5"}`).

## 7. Dimensionamiento y ajustes

| Variable | Recomendación (4 vCPU / 8 GB) |
|---|---|
| `ENGINE_WORKERS` | 2 (ejecuciones concurrentes del motor) |
| `ENGINE_N_JOBS` | 2 (hilos de LightGBM por ejecución) |
| `ENGINE_MEMORY_LIMIT` | 4g |
| Worker `queue-forecasting` | 1 réplica; `docker compose up -d --scale queue-forecasting=2` si hay muchas empresas |
| PostgreSQL | `shared_buffers` ≈ 25 % RAM, `work_mem` 16–32 MB (archivo `postgresql.conf` o `command:` en compose) |

## 8. Construcción detrás de un proxy TLS (solo entornos corporativos)

Los Dockerfiles aceptan, de forma opcional, un CA adicional y espejos HTTPS:

```bash
docker build --network host --build-arg https_proxy=http://proxy:3128 --build-arg APT_HTTPS=true \
  --secret id=extra_ca,src=/ruta/ca.crt -t predictily/backend backend
```

En CI con límites de tasa de GitHub para Composer: `--secret id=composer_auth,src=auth.json`
(contenido de `COMPOSER_AUTH`, p. ej. `{"github-oauth":{"github.com":"<token de solo lectura>"}}`).
