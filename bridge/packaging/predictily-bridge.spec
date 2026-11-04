# -*- mode: python ; coding: utf-8 -*-
# DISEÑADO · NO CONSTRUIDO NI PROBADO (BLOQUEADO): empaquetar para Windows requiere un host Windows y, para distribuir sin advertencias de SmartScreen,
# el certificado de firma de código del PROPIETARIO. Pasos exactos: docs/integrations/BRIDGE_WINDOWS.md §Empaquetado.
#
#   (en Windows, PowerShell, dentro de bridge/)
#   py -3.12 -m venv .venv ; .venv\Scripts\pip install -e ".[postgres,sqlserver,mysql,excel,keyring,windows-package]"
#   .venv\Scripts\pyinstaller packaging\predictily-bridge.spec --noconfirm
#   # → dist\predictily-bridge\predictily-bridge.exe (carpeta, no «onefile»: arranca más rápido y los antivirus lo marcan menos)
#
# Sin secretos: la configuración, las plantillas y las credenciales se crean en el equipo del cliente (ProgramData), nunca dentro del paquete.
from PyInstaller.utils.hooks import collect_submodules

hidden = collect_submodules("predictily_bridge") + ["win32timezone", "win32serviceutil", "win32service", "win32event", "servicemanager"]
for optional in ("psycopg", "pyodbc", "pymysql", "openpyxl", "keyring.backends.Windows"):
    try:
        hidden += collect_submodules(optional)
    except Exception:  # extra no instalado: se omite
        pass

a = Analysis(
    ["../src/predictily_bridge/__main__.py"],
    pathex=["../src"],
    hiddenimports=hidden,
    excludes=["tkinter", "unittest", "pytest"],
    noarchive=False,
)
pyz = PYZ(a.pure)
exe = EXE(
    pyz, a.scripts, [],
    exclude_binaries=True,
    name="predictily-bridge",
    console=True,
    # La firma de código (signtool) se aplica DESPUÉS de construir, con el certificado del propietario: ver BRIDGE_WINDOWS.md.
    codesign_identity=None,
)
coll = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False, name="predictily-bridge")
