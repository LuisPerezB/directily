"""Códigos de salida estables de la CLI de entrenamiento privado (ADR-V25-19) y el error que los transporta.

Tabla (documentada en docs/ml/PRIVATE_TENANT_TRAINING.md §2; no cambia entre versiones sin avisar en el documento):

| Código | Nombre        | Significado |
|---|---|---|
| 0 | EXIT_OK          | Terminó bien (puede incluir avisos; los lleva la salida JSON). |
| 1 | EXIT_CHECK_FAILED| La comprobación pedida dio NEGATIVO: verificación de firma fallida, gate de calidad BLOCK, destino de reversión no servible, diagnóstico con fallos. No es un error del programa. |
| 2 | EXIT_USAGE       | Argumentos o uso inválidos (también los de argparse) o valor fuera de rango. |
| 3 | EXIT_CONFIG      | Entorno o configuración inválidos o ausentes: claves de firma, base de datos, almacén de objetos, biblioteca opcional. |
| 4 | EXIT_NOT_AUTHORIZED | Falta una autorización explícita o el actor no corresponde: exportación sin referencia, publicador ajeno a la empresa, publicación a producción sin confirmar. |
| 5 | EXIT_NOT_FOUND   | La empresa, el modelo, el paquete o la exportación no existen. |
| 6 | EXIT_CONFLICT    | Se negó a sobrescribir: versión ya publicada con otro contenido, destino existente, objeto ya presente con otro contenido. |
| 7 | EXIT_INTERNAL    | Fallo inesperado (el mensaje solo trae el tipo de error; sin rutas de claves ni credenciales). |
"""

from __future__ import annotations

EXIT_OK = 0
EXIT_CHECK_FAILED = 1
EXIT_USAGE = 2
EXIT_CONFIG = 3
EXIT_NOT_AUTHORIZED = 4
EXIT_NOT_FOUND = 5
EXIT_CONFLICT = 6
EXIT_INTERNAL = 7

EXIT_CODES = {
    EXIT_OK: "ok", EXIT_CHECK_FAILED: "comprobación negativa", EXIT_USAGE: "uso inválido", EXIT_CONFIG: "entorno o configuración",
    EXIT_NOT_AUTHORIZED: "sin autorización", EXIT_NOT_FOUND: "no encontrado", EXIT_CONFLICT: "conflicto", EXIT_INTERNAL: "error interno",
}


class CliError(Exception):
    """Un fallo esperado de un comando: se imprime `error: <mensaje>` en stderr y se sale con `code`. El mensaje nunca lleva secretos."""

    def __init__(self, code: int, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
