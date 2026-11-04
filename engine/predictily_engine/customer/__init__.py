"""Customer Core (etapa 2, paquete P2): comportamiento de compra por comprador × producto.

Definiciones, protocolo y limitaciones: docs/architecture/v2/P2-comportamiento.md.
"""

from predictily_engine.platform.config import HORIZONS

CORE = "customer"
# Horizontes del Customer Core: subconjunto del catálogo único (platform.config.HORIZONS["customer"]; 60 queda fuera, ver allí).
SUPPORTED_HORIZONS = tuple(sorted(HORIZONS["customer"]))
FEATURE_SCHEMA_VERSION = "cust-fs-2026.10-v1"
LABEL_DEFINITION_VERSION = "cust-label-2026.10-v1"
# W3-C (docs/ml/CUSTOMER_REPURCHASE.md): nº de pedidos, cantidad por pedido, unidades, ventana, segmentos, ranking económico y drift.
S05_VERSION = "cust-s05-2026.11-v1"
