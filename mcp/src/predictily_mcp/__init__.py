"""Servidores MCP de Predictily.

Dos servidores, ambos CLIENTES de la API pública `/api/v1` con el token personal de cada persona:

- `ingest`: siembra de datos desde el sistema del cliente (fuentes SQL, mapeos, sincronizaciones, archivos).
- `insights`: consulta de los análisis ya calculados (pronósticos, inventario, radar, decisiones, resultados).

Ninguno calcula cifras ni escribe datos de negocio por su cuenta: todo pasa por la API, que aplica las habilidades del
rol y del token, el aislamiento por empresa y el alcance comercial. Ver `docs/integrations/MCP_SERVERS.md`.
"""

__version__ = "0.1.0"
