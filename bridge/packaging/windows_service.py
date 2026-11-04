"""DISEÑADO · NO PROBADO (BLOQUEADO: requiere host Windows). Contenedor de servicio de Windows para el agente (pywin32).

Alternativa sin código propio: registrar `predictily-bridge.exe --home C:\\ProgramData\\Predictily\\Bridge run` con NSSM o WinSW. Este módulo es
el diseño del servicio nativo: el SCM llama a `SvcStop`, que fija la bandera de parada; el bucle del agente la consulta cada segundo y
termina el ciclo en curso de forma limpia (los lotes ya confirmados no se pierden; el búfer cifrado sobrevive a reinicios).

Instalación (PowerShell de administrador, cuenta de servicio dedicada SIN privilegios; el directorio del agente solo para ella y SYSTEM):
    predictily-bridge-service.exe --startup auto install
    sc.exe config PredictilyBridge obj= ".\\svc-predictily-bridge" password= *****   # (la contraseña se teclea, no se guarda en scripts)
    icacls "C:\\ProgramData\\Predictily\\Bridge" /inheritance:r /grant:r "svc-predictily-bridge:(OI)(CI)F" "SYSTEM:(OI)(CI)F"
    sc.exe failure PredictilyBridge reset= 86400 actions= restart/60000/restart/60000/restart/60000
"""

from __future__ import annotations

import os
import sys
import threading

import servicemanager  # type: ignore[import-not-found]
import win32event  # type: ignore[import-not-found]
import win32service  # type: ignore[import-not-found]
import win32serviceutil  # type: ignore[import-not-found]

from predictily_bridge import cli


class PredictilyBridgeService(win32serviceutil.ServiceFramework):
    _svc_name_ = "PredictilyBridge"
    _svc_display_name_ = "Predictily Bridge (solo lectura)"
    _svc_description_ = "Extrae del ERP del cliente con consultas SELECT autorizadas localmente y sube lotes cifrados en tránsito a Predictily."

    def __init__(self, args) -> None:  # type: ignore[no-untyped-def]
        super().__init__(args)
        self._stop_event = threading.Event()
        self._wait = win32event.CreateEvent(None, 0, 0, None)

    def SvcStop(self) -> None:  # noqa: N802 - nombre exigido por pywin32
        self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
        self._stop_event.set()
        win32event.SetEvent(self._wait)

    def SvcDoRun(self) -> None:  # noqa: N802
        servicemanager.LogMsg(servicemanager.EVENTLOG_INFORMATION_TYPE, servicemanager.PYS_SERVICE_STARTED, (self._svc_name_, ""))
        home = os.environ.get("PREDICTILY_BRIDGE_HOME", r"C:\ProgramData\Predictily\Bridge")
        # El bucle del agente consulta `stop()` cada segundo: la parada es limpia (no a mitad de un lote sin confirmar).
        code = cli.run_service(home, stop=self._stop_event.is_set)
        servicemanager.LogMsg(servicemanager.EVENTLOG_INFORMATION_TYPE, servicemanager.PYS_SERVICE_STOPPED, (self._svc_name_, f"código {code}"))


if __name__ == "__main__":
    if len(sys.argv) == 1:
        servicemanager.Initialize()
        servicemanager.PrepareToHostSingle(PredictilyBridgeService)
        servicemanager.StartServiceCtrlDispatcher()
    else:
        win32serviceutil.HandleCommandLine(PredictilyBridgeService)
