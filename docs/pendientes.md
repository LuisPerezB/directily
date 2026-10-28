# Funcionalidades pendientes y dependencias externas

Lista inicial (docs-infra). El estado final de cada componente se consolida en
`docs/00-registro-avance.md`. Ninguna integración externa de esta lista se presenta como activa
en la aplicación: se entregan interfaces desacopladas y se indica su estado real.

## 1. Dependencias externas (requieren credenciales, contratos o verificación)

| Elemento | Situación | Qué falta | Dónde se integra |
|---|---|---|---|
| Dominio `predictily.com` | No verificado | Confirmar disponibilidad y titularidad; registrar marca | `PREDICTILY_DOMAIN`, `infra/caddy/Caddyfile` |
| Conectores ERP/POS de Ecuador | Interfaz de conectores + importador CSV/Excel + API REST | Credenciales de solo lectura y documentación de cada sistema; muchos no tienen API pública → exportación programada (CSV/SFTP) o lectura de BD | `data_sources`, contrato `Connector` (backend) |
| INEC (IPC, inflación) | Esquema y consultas as-of listos | Conector de descarga/parseo y calendario de publicaciones | `external_indicators*` (F4-04) |
| Banco Central del Ecuador (actividad, tasas) | Igual | Conector y frecuencia de actualización | F4-04 |
| Precios de combustibles / materias primas / clima | Igual | Fuente oficial, licencia de uso de datos | F4-04 |
| TimesFM 2.5 (modelo fundacional) | Adaptador experimental desactivado | Verificar licencia comercial de código y pesos, rendimiento en CPU sin GPU, dependencias (PyTorch/JAX) | Motor, Nivel E (F4-06) |
| Pasarela de pagos | Planes asignados manualmente por superadmin | Proveedor (p. ej. local o internacional), facturación electrónica SRI | F4-13 |
| Correo transaccional (SMTP) | `MAIL_MAILER=log` por defecto | Proveedor SMTP, SPF/DKIM/DMARC del dominio | `.env` (MAIL_*) |
| Certificados TLS | Automáticos con Caddy + Let's Encrypt | DNS del dominio apuntando al VPS, puertos 80/443 | `docker-compose.prod.yml` |
| Almacenamiento externo de respaldos | Respaldos locales con rotación | Bucket S3-compatible u otro proveedor; clave GPG | `infra/scripts/backup.sh` |
| Asesoría legal LOPDP | Plan técnico en docs/07 | Contrato de encargo, política de privacidad, términos, EIPD, registro ante la autoridad si aplica | docs/07 |

## 2. Pendientes concretos del MVP entregado

| Pendiente | Impacto | Notas |
|---|---|---|
| Invitaciones y recuperación de contraseña por correo | Medio | Hoy la contraseña temporal de un usuario agregado se muestra una sola vez; requiere SMTP |
| Pantalla para cancelar una solicitud de eliminación de empresa | Bajo | El endpoint `POST /tenant/cancel-deletion` existe; falta la vista |
| Reconstruir y probar la imagen `web` (frontend + nginx) | Medio | Falló antes de corregir errores de tipos del frontend; el build nativo ya pasa |
| Descarga de la exportación completa detrás de nginx | Bajo | URL firmada absoluta basada en `APP_URL`: verificar en el despliegue |
| Sesgo de −7 % en el backtest de la ferretería demo | Bajo | Las 3 ventanas caen en temporada alta; considerar ventanas repartidas en el año |
| Intervalos de horizonte por sucursal-producto (nivel serie) | Bajo | El motor los publica para producto, categoría, sucursal y total |
| Row Level Security en PostgreSQL | Medio | Endurecimiento adicional; el aislamiento actual tiene 9 capas (docs/07) |
| Supervisor de procesos en instalación nativa | Bajo | Docker reinicia los workers; en nativo usar systemd/supervisord |

## 3. Funcionalidades de Fase 4 o posteriores

- Modelos personalizados por empresa (Nivel D) y sectoriales (Nivel C, requiere consentimiento).
- Reentrenamiento automático por drift con publicación condicionada a evaluación.
- Indicadores macroeconómicos en el modelo (con escenarios para valores futuros desconocidos).
- Recomendaciones avanzadas (presupuesto, lotes mínimos, transferencias entre sucursales).
- Elasticidad precio-demanda y estimación causal (el simulador actual es determinista).
- Explicaciones en lenguaje natural con LLM (siempre a partir de datos calculados).
- Segmentación de clientes y análisis geográfico en la interfaz.
- Row Level Security en PostgreSQL, 2FA, SSO, escaneo de imágenes y pentest.
- Archivado WAL continuo (pgBackRest) para RPO < 24 h.
- Internacionalización a otros países (feriados, monedas, idioma).

## 4. Notas de infraestructura

- **Construcción de imágenes en CI:** `composer install` dentro de Docker descarga paquetes desde
  GitHub; con IPs compartidas puede alcanzar límites de tasa. Usar el secreto de build opcional
  `composer_auth` (`--secret id=composer_auth,src=auth.json`, JSON de `COMPOSER_AUTH` con un token
  de GitHub de solo lectura).
- **Redes con proxy TLS:** los Dockerfiles aceptan opcionalmente `--secret id=extra_ca` y
  `--build-arg APT_HTTPS=true`; no son necesarios en un VPS normal.
- **Escalado:** si el volumen crece, particionar `sale_items` y `forecast_values` (por tenant o
  por rango de fechas/ejecución) y separar el motor en otro host con TLS hacia PostgreSQL.
