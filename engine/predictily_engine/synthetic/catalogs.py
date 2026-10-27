"""Catálogos FICTICIOS para las empresas de demostración.

Nombres de empresas, marcas, clientes y proveedores son inventados. Los productos son
genéricos de ferretería y consumo masivo, sin marcas reales.
"""

from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(frozen=True)
class BranchSpec:
    code: str
    name: str
    province: str
    canton: str
    commercial_zone: str
    size: float  # multiplicador de volumen


@dataclass(frozen=True)
class ProductSpec:
    sku: str
    name: str
    category: str
    unit: str
    base_price: float
    base_demand: float  # unidades/día en una sucursal de tamaño 1
    cost_ratio: float = 0.7
    profile: str = "default"  # perfil estacional
    purchase_size: float = 2.0  # unidades típicas por línea
    iva: float = 0.15
    lead_time: int = 7
    supplier: str = "PROV-01"
    launch: str | None = None  # fecha de lanzamiento (serie corta)
    discontinue: str | None = None  # fecha de descontinuación (serie inactiva)


@dataclass(frozen=True)
class PromoSpec:
    name: str
    discount_pct: float
    starts_on: str
    ends_on: str
    category: str | None = None
    sku: str | None = None
    branch_code: str | None = None
    lift: float = 1.0  # elevación aproximada de demanda usada SOLO para simular


@dataclass(frozen=True)
class SupplierSpec:
    code: str
    name: str
    country: str
    default_lead_time_days: int


@dataclass(frozen=True)
class CompanySpec:
    slug: str
    name: str
    sector: str
    start: str
    end: str
    region: str
    branches: list[BranchSpec]
    products: list[ProductSpec]
    promotions: list[PromoSpec]
    suppliers: list[SupplierSpec]
    weekly: tuple[float, ...]  # lunes..domingo
    closed_holidays: tuple[str, ...]
    trend_per_year: float
    segments: list[tuple[str, str, float, str, float]] = field(default_factory=list)
    # (código, nombre, probabilidad, canal, descuento)
    doc_prefix_point: str = "001"
    credit_point: str = "002"


def _p(sku, name, cat, unit, price, demand, **kw) -> ProductSpec:
    return ProductSpec(sku=sku, name=name, category=cat, unit=unit, base_price=price, base_demand=demand, **kw)


FERRETERIA_ANDINA = CompanySpec(
    slug="ferreteria_andina",
    name="Ferretería Andina (Demo ficticia)",
    sector="ferreteria",
    start="2024-10-27",
    end="2026-10-26",
    region="sierra",
    branches=[
        BranchSpec("001", "Matriz Quito Norte", "Pichincha", "Quito", "Norte", 1.35),
        BranchSpec("002", "Sucursal Cuenca", "Azuay", "Cuenca", "Centro", 1.0),
        BranchSpec("003", "Sucursal Ambato", "Tungurahua", "Ambato", "Sur", 0.75),
    ],
    weekly=(1.0, 0.95, 0.95, 1.0, 1.1, 1.35, 0.45),
    closed_holidays=("Año Nuevo", "Navidad", "Viernes Santo"),
    trend_per_year=0.06,
    segments=[
        ("CF", "Consumidor final", 0.55, "store", 0.0),
        ("MO", "Maestro de obra", 0.25, "store", 0.02),
        ("CO", "Constructora", 0.10, "wholesale", 0.05),
        ("FM", "Ferretería minorista", 0.10, "wholesale", 0.04),
    ],
    suppliers=[
        SupplierSpec("PROV-CEM", "Cementos del Valle S.A. (ficticio)", "EC", 3),
        SupplierSpec("PROV-ACE", "Aceros del Austro Cía. Ltda. (ficticio)", "EC", 5),
        SupplierSpec("PROV-PIN", "Pinturas Cordillera S.A. (ficticio)", "EC", 7),
        SupplierSpec("PROV-HER", "Importadora Herramientas Pacífico (ficticio)", "CN", 21),
        SupplierSpec("PROV-ELE", "Distribuidora Eléctrica Central (ficticio)", "EC", 10),
        SupplierSpec("PROV-PLO", "Plásticos y Tuberías Andinas (ficticio)", "EC", 7),
        SupplierSpec("PROV-GEN", "Comercial Ferretera Mayorista (ficticio)", "EC", 10),
    ],
    products=[
        # Cemento y agregados (perfil construcción: temporada seca en la Sierra)
        _p("FA-CEM-001", "Cemento Portland tipo GU 50 kg", "Cemento y agregados", "saco", 8.40, 9.0, cost_ratio=0.82, profile="construction", purchase_size=6, lead_time=3, supplier="PROV-CEM"),
        _p("FA-CEM-002", "Cemento blanco 25 kg", "Cemento y agregados", "saco", 12.90, 0.9, cost_ratio=0.75, profile="construction", purchase_size=1.5, lead_time=5, supplier="PROV-CEM"),
        _p("FA-CEM-003", "Bloque de hormigón 15 cm", "Cemento y agregados", "unidad", 0.62, 38.0, cost_ratio=0.70, profile="construction", purchase_size=60, lead_time=3, supplier="PROV-CEM"),
        _p("FA-CEM-004", "Cal hidratada 25 kg", "Cemento y agregados", "saco", 5.10, 1.6, cost_ratio=0.72, profile="construction", purchase_size=2, lead_time=4, supplier="PROV-CEM"),
        _p("FA-CEM-005", "Pegamento para cerámica 25 kg", "Cemento y agregados", "saco", 7.80, 2.4, cost_ratio=0.70, profile="construction", purchase_size=3, lead_time=5, supplier="PROV-CEM"),
        _p("FA-CEM-006", "Porcelana para juntas 2 kg", "Cemento y agregados", "funda", 3.20, 1.8, cost_ratio=0.62, profile="construction", purchase_size=1.5, lead_time=5, supplier="PROV-CEM"),
        _p("FA-CEM-007", "Arena fina (saco 40 kg)", "Cemento y agregados", "saco", 2.10, 5.0, cost_ratio=0.60, profile="construction", purchase_size=5, lead_time=2, supplier="PROV-CEM"),
        _p("FA-CEM-008", "Ripio triturado (saco 40 kg)", "Cemento y agregados", "saco", 2.30, 3.5, cost_ratio=0.60, profile="construction", purchase_size=5, lead_time=2, supplier="PROV-CEM"),
        # Hierro y varillas
        _p("FA-HIE-001", "Varilla corrugada 10 mm x 12 m", "Hierro y varillas", "unidad", 9.60, 4.5, cost_ratio=0.85, profile="construction", purchase_size=6, lead_time=5, supplier="PROV-ACE"),
        _p("FA-HIE-002", "Varilla corrugada 12 mm x 12 m", "Hierro y varillas", "unidad", 13.80, 3.2, cost_ratio=0.85, profile="construction", purchase_size=6, lead_time=5, supplier="PROV-ACE"),
        _p("FA-HIE-003", "Varilla corrugada 8 mm x 12 m", "Hierro y varillas", "unidad", 6.20, 2.6, cost_ratio=0.85, profile="construction", purchase_size=5, lead_time=5, supplier="PROV-ACE"),
        _p("FA-HIE-004", "Alambre de amarre (kg)", "Hierro y varillas", "kg", 2.40, 3.0, cost_ratio=0.72, profile="construction", purchase_size=2, lead_time=5, supplier="PROV-ACE"),
        _p("FA-HIE-005", "Malla electrosoldada 6 mm 2.4x6 m", "Hierro y varillas", "unidad", 46.00, 0.35, cost_ratio=0.83, profile="construction", purchase_size=2, lead_time=7, supplier="PROV-ACE"),
        _p("FA-HIE-006", "Clavos de 2 pulgadas (kg)", "Hierro y varillas", "kg", 2.20, 2.8, cost_ratio=0.65, profile="construction", purchase_size=1.5, lead_time=7, supplier="PROV-ACE"),
        _p("FA-HIE-007", "Tubo estructural cuadrado 2 pulg. x 6 m", "Hierro y varillas", "unidad", 18.50, 0.8, cost_ratio=0.82, profile="construction", purchase_size=2, lead_time=7, supplier="PROV-ACE"),
        # Pinturas
        _p("FA-PIN-001", "Pintura látex interior blanco (galón)", "Pinturas", "galón", 14.50, 2.6, cost_ratio=0.66, profile="paint", purchase_size=1.4, lead_time=7, supplier="PROV-PIN"),
        _p("FA-PIN-002", "Pintura látex interior colores (galón)", "Pinturas", "galón", 15.90, 1.9, cost_ratio=0.66, profile="paint", purchase_size=1.3, lead_time=7, supplier="PROV-PIN"),
        _p("FA-PIN-003", "Esmalte sintético (galón)", "Pinturas", "galón", 21.00, 0.8, cost_ratio=0.65, profile="paint", purchase_size=1.2, lead_time=7, supplier="PROV-PIN"),
        _p("FA-PIN-004", "Pintura exteriores (caneca 5 gal.)", "Pinturas", "caneca", 62.00, 0.25, cost_ratio=0.68, profile="paint", purchase_size=1.0, lead_time=10, supplier="PROV-PIN"),
        _p("FA-PIN-005", "Diluyente thinner (litro)", "Pinturas", "litro", 2.90, 2.2, cost_ratio=0.55, profile="paint", purchase_size=1.5, lead_time=7, supplier="PROV-PIN"),
        _p("FA-PIN-006", "Brocha 3 pulgadas", "Pinturas", "unidad", 2.40, 1.6, cost_ratio=0.50, profile="paint", purchase_size=1.2, lead_time=10, supplier="PROV-PIN"),
        _p("FA-PIN-007", "Rodillo de 9 pulgadas", "Pinturas", "unidad", 4.80, 1.1, cost_ratio=0.52, profile="paint", purchase_size=1.1, lead_time=10, supplier="PROV-PIN"),
        _p("FA-PIN-008", "Empaste para paredes (galón)", "Pinturas", "galón", 9.20, 1.3, cost_ratio=0.62, profile="paint", purchase_size=1.3, lead_time=7, supplier="PROV-PIN"),
        # Herramientas manuales (importadas: plazos largos)
        _p("FA-HMA-001", "Martillo de uña 16 oz", "Herramientas manuales", "unidad", 7.90, 0.55, cost_ratio=0.55, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-002", "Juego de destornilladores 6 piezas", "Herramientas manuales", "juego", 9.50, 0.45, cost_ratio=0.52, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-003", "Flexómetro 5 m", "Herramientas manuales", "unidad", 4.20, 0.9, cost_ratio=0.50, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-004", "Nivel de aluminio 24 pulgadas", "Herramientas manuales", "unidad", 11.00, 0.22, cost_ratio=0.55, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-005", "Pala cuadrada con mango", "Herramientas manuales", "unidad", 12.50, 0.5, cost_ratio=0.60, profile="construction", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-006", "Carretilla 5 pies cúbicos", "Herramientas manuales", "unidad", 68.00, 0.12, cost_ratio=0.66, profile="construction", purchase_size=1.0, lead_time=28, supplier="PROV-HER"),
        _p("FA-HMA-007", "Llave inglesa 10 pulgadas", "Herramientas manuales", "unidad", 8.30, 0.25, cost_ratio=0.53, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HMA-008", "Serrucho 20 pulgadas", "Herramientas manuales", "unidad", 9.90, 0.18, cost_ratio=0.54, profile="tools", purchase_size=1.0, lead_time=21, supplier="PROV-HER"),
        # Herramientas eléctricas
        _p("FA-HEL-001", "Taladro percutor 1/2 pulg. 650 W", "Herramientas eléctricas", "unidad", 54.00, 0.16, cost_ratio=0.68, profile="power_tools", purchase_size=1.0, lead_time=30, supplier="PROV-HER"),
        _p("FA-HEL-002", "Amoladora 4 1/2 pulg. 850 W", "Herramientas eléctricas", "unidad", 49.00, 0.14, cost_ratio=0.68, profile="power_tools", purchase_size=1.0, lead_time=30, supplier="PROV-HER"),
        _p("FA-HEL-003", "Sierra circular 7 1/4 pulg.", "Herramientas eléctricas", "unidad", 89.00, 0.05, cost_ratio=0.70, profile="power_tools", purchase_size=1.0, lead_time=30, supplier="PROV-HER"),
        _p("FA-HEL-004", "Disco de corte metal 4 1/2 pulg.", "Herramientas eléctricas", "unidad", 1.60, 4.0, cost_ratio=0.45, profile="construction", purchase_size=3.0, lead_time=21, supplier="PROV-HER"),
        _p("FA-HEL-005", "Broca para concreto 3/8 pulg.", "Herramientas eléctricas", "unidad", 2.80, 1.2, cost_ratio=0.45, profile="construction", purchase_size=1.5, lead_time=21, supplier="PROV-HER"),
        _p("FA-HEL-006", "Lijadora orbital 1/4 hoja", "Herramientas eléctricas", "unidad", 45.00, 0.04, cost_ratio=0.69, profile="power_tools", purchase_size=1.0, lead_time=30, supplier="PROV-HER"),
        # Material eléctrico
        _p("FA-ELE-001", "Cable flexible #12 AWG (metro)", "Material eléctrico", "metro", 0.68, 28.0, cost_ratio=0.70, purchase_size=20, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-002", "Cable flexible #14 AWG (metro)", "Material eléctrico", "metro", 0.48, 22.0, cost_ratio=0.70, purchase_size=20, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-003", "Foco LED 9 W luz blanca", "Material eléctrico", "unidad", 1.90, 6.0, cost_ratio=0.48, purchase_size=2.5, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-004", "Tomacorriente doble polarizado", "Material eléctrico", "unidad", 2.30, 3.0, cost_ratio=0.50, purchase_size=2.0, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-005", "Interruptor simple", "Material eléctrico", "unidad", 1.95, 2.6, cost_ratio=0.50, purchase_size=2.0, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-006", "Cinta aislante 20 m", "Material eléctrico", "unidad", 0.95, 4.0, cost_ratio=0.45, purchase_size=1.5, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-007", "Breaker termomagnético 20 A", "Material eléctrico", "unidad", 6.40, 0.7, cost_ratio=0.58, purchase_size=1.2, lead_time=10, supplier="PROV-ELE"),
        _p("FA-ELE-008", "Luces navideñas 100 LED", "Material eléctrico", "unidad", 6.90, 0.03, cost_ratio=0.45, profile="christmas_lights", purchase_size=1.5, lead_time=30, supplier="PROV-ELE"),
        _p("FA-ELE-009", "Foco LED inteligente WiFi 10 W", "Material eléctrico", "unidad", 8.90, 1.2, cost_ratio=0.55, purchase_size=1.2, lead_time=21, supplier="PROV-ELE", launch="2026-08-20"),
        _p("FA-ELE-010", "Foco ahorrador fluorescente 20 W", "Material eléctrico", "unidad", 2.60, 1.5, cost_ratio=0.50, purchase_size=1.5, lead_time=10, supplier="PROV-ELE", discontinue="2026-03-31"),
        # Plomería y grifería
        _p("FA-PLO-001", "Tubo PVC presión 1/2 pulg. x 6 m", "Plomería y grifería", "unidad", 3.40, 4.0, cost_ratio=0.62, profile="construction", purchase_size=3.0, lead_time=7, supplier="PROV-PLO"),
        _p("FA-PLO-002", "Tubo PVC desagüe 4 pulg. x 3 m", "Plomería y grifería", "unidad", 8.70, 1.4, cost_ratio=0.64, profile="construction", purchase_size=2.0, lead_time=7, supplier="PROV-PLO"),
        _p("FA-PLO-003", "Codo PVC 1/2 pulg. 90°", "Plomería y grifería", "unidad", 0.35, 12.0, cost_ratio=0.45, purchase_size=6.0, lead_time=7, supplier="PROV-PLO"),
        _p("FA-PLO-004", "Llave de paso 1/2 pulg.", "Plomería y grifería", "unidad", 4.60, 1.3, cost_ratio=0.55, purchase_size=1.2, lead_time=10, supplier="PROV-PLO"),
        _p("FA-PLO-005", "Grifería para lavamanos", "Plomería y grifería", "unidad", 24.00, 0.2, cost_ratio=0.60, purchase_size=1.0, lead_time=14, supplier="PROV-PLO"),
        _p("FA-PLO-006", "Cinta teflón 1/2 pulg.", "Plomería y grifería", "unidad", 0.40, 7.0, cost_ratio=0.40, purchase_size=2.0, lead_time=7, supplier="PROV-PLO"),
        _p("FA-PLO-007", "Pegamento para PVC 1/4 galón", "Plomería y grifería", "unidad", 7.20, 0.9, cost_ratio=0.58, purchase_size=1.0, lead_time=7, supplier="PROV-PLO"),
        _p("FA-PLO-008", "Inodoro estándar blanco", "Plomería y grifería", "unidad", 79.00, 0.06, cost_ratio=0.66, purchase_size=1.0, lead_time=14, supplier="PROV-PLO"),
        # Ferretería general
        _p("FA-GEN-001", "Tornillo autoperforante (caja 100)", "Ferretería general", "caja", 4.50, 1.8, cost_ratio=0.50, purchase_size=1.2, lead_time=10, supplier="PROV-GEN"),
        _p("FA-GEN-002", "Taco fisher 3/8 (caja 50)", "Ferretería general", "caja", 3.10, 1.5, cost_ratio=0.48, purchase_size=1.2, lead_time=10, supplier="PROV-GEN"),
        _p("FA-GEN-003", "Candado de acero 40 mm", "Ferretería general", "unidad", 6.80, 0.6, cost_ratio=0.52, purchase_size=1.0, lead_time=21, supplier="PROV-GEN"),
        _p("FA-GEN-004", "Bisagra 3 pulgadas (par)", "Ferretería general", "par", 1.80, 1.6, cost_ratio=0.48, purchase_size=2.0, lead_time=10, supplier="PROV-GEN"),
        _p("FA-GEN-005", "Silicón transparente 280 ml", "Ferretería general", "unidad", 3.90, 2.0, cost_ratio=0.52, purchase_size=1.3, lead_time=10, supplier="PROV-GEN"),
        _p("FA-GEN-006", "Guantes de trabajo (par)", "Ferretería general", "par", 2.20, 2.4, cost_ratio=0.45, profile="construction", purchase_size=1.5, lead_time=10, supplier="PROV-GEN"),
        _p("FA-GEN-007", "Lija de agua #120", "Ferretería general", "unidad", 0.45, 5.0, cost_ratio=0.40, profile="paint", purchase_size=3.0, lead_time=10, supplier="PROV-GEN"),
    ],
    promotions=[
        PromoSpec("Feria de la pintura 2025", 0.15, "2025-03-10", "2025-03-23", category="Pinturas", lift=1.45),
        PromoSpec("Black Friday ferretero 2025", 0.12, "2025-11-28", "2025-12-01", category="Herramientas eléctricas", lift=1.9),
        PromoSpec("Semana del constructor 2025", 0.06, "2025-07-07", "2025-07-13", sku="FA-CEM-001", lift=1.35),
        PromoSpec("Feria de la pintura 2026", 0.15, "2026-03-09", "2026-03-22", category="Pinturas", lift=1.45),
        PromoSpec("Liquidación focos fluorescentes", 0.30, "2026-02-01", "2026-03-31", sku="FA-ELE-010", lift=1.8),
        PromoSpec("Semana del constructor 2026", 0.06, "2026-07-06", "2026-07-12", sku="FA-CEM-001", lift=1.35),
        # Promoción futura (posterior al último dato): variable conocida de antemano.
        PromoSpec("Black Friday ferretero 2026", 0.12, "2026-11-27", "2026-11-30", category="Herramientas eléctricas", lift=1.9),
    ],
)


DISTRIBUIDORA_COSTA = CompanySpec(
    slug="distribuidora_costa",
    name="Distribuidora Costa (Demo ficticia)",
    sector="distribuidora",
    start="2025-07-27",
    end="2026-10-26",
    region="costa",
    branches=[
        BranchSpec("001", "Centro de distribución Guayaquil", "Guayas", "Guayaquil", "Norte", 1.4),
        BranchSpec("002", "Sucursal Manta", "Manabí", "Manta", "Centro", 0.8),
    ],
    weekly=(1.15, 1.0, 1.0, 1.05, 1.1, 1.2, 0.3),
    closed_holidays=("Año Nuevo", "Navidad"),
    trend_per_year=0.04,
    segments=[
        ("TB", "Tienda de barrio", 0.55, "field_sales", 0.02),
        ("MM", "Minimarket", 0.20, "wholesale", 0.04),
        ("RE", "Restaurante", 0.10, "wholesale", 0.03),
        ("CF", "Consumidor final", 0.15, "store", 0.0),
    ],
    suppliers=[
        SupplierSpec("PROV-ABA", "Alimentos del Litoral S.A. (ficticio)", "EC", 3),
        SupplierSpec("PROV-BEB", "Embotelladora Costa Azul (ficticio)", "EC", 2),
        SupplierSpec("PROV-LAC", "Lácteos Manabitas Cía. Ltda. (ficticio)", "EC", 2),
        SupplierSpec("PROV-LIM", "Químicos del Hogar S.A. (ficticio)", "EC", 5),
        SupplierSpec("PROV-ESC", "Papelería Escolar Pacífico (ficticio)", "EC", 7),
    ],
    products=[
        _p("DC-ABA-001", "Arroz blanco 2 kg", "Abarrotes", "funda", 2.35, 24.0, cost_ratio=0.86, purchase_size=6, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-002", "Azúcar blanca 2 kg", "Abarrotes", "funda", 2.10, 16.0, cost_ratio=0.86, purchase_size=5, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-003", "Aceite vegetal 1 litro", "Abarrotes", "botella", 2.65, 14.0, cost_ratio=0.84, purchase_size=4, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-004", "Fideo tallarín 400 g", "Abarrotes", "funda", 0.85, 18.0, cost_ratio=0.78, purchase_size=6, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-005", "Atún en aceite 180 g", "Abarrotes", "lata", 1.55, 15.0, cost_ratio=0.74, purchase_size=6, lead_time=4, supplier="PROV-ABA"),
        _p("DC-ABA-006", "Harina de trigo 1 kg", "Abarrotes", "funda", 1.20, 6.0, cost_ratio=0.80, purchase_size=3, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-007", "Sal yodada 1 kg", "Abarrotes", "funda", 0.55, 5.5, cost_ratio=0.70, purchase_size=4, iva=0.0, lead_time=3, supplier="PROV-ABA", profile="staples"),
        _p("DC-ABA-008", "Avena en hojuelas 500 g", "Abarrotes", "funda", 1.35, 4.0, cost_ratio=0.75, purchase_size=3, iva=0.0, lead_time=4, supplier="PROV-ABA"),
        _p("DC-BEB-001", "Agua sin gas 625 ml (pack 12)", "Bebidas", "pack", 4.20, 9.0, cost_ratio=0.70, purchase_size=2.5, lead_time=2, supplier="PROV-BEB", profile="beverages"),
        _p("DC-BEB-002", "Bebida gaseosa 3 litros", "Bebidas", "botella", 2.40, 13.0, cost_ratio=0.72, purchase_size=4, lead_time=2, supplier="PROV-BEB", profile="beverages"),
        _p("DC-BEB-003", "Jugo de naranja 1 litro", "Bebidas", "botella", 1.75, 6.5, cost_ratio=0.70, purchase_size=3, lead_time=2, supplier="PROV-BEB", profile="beverages"),
        _p("DC-BEB-004", "Bebida energizante 473 ml", "Bebidas", "lata", 1.25, 5.0, cost_ratio=0.66, purchase_size=6, lead_time=3, supplier="PROV-BEB", profile="beverages"),
        _p("DC-BEB-005", "Bebida hidratante 500 ml", "Bebidas", "botella", 0.95, 7.5, cost_ratio=0.66, purchase_size=6, lead_time=3, supplier="PROV-BEB", profile="beverages"),
        _p("DC-LAC-001", "Leche entera UHT 1 litro", "Lácteos", "caja", 1.05, 22.0, cost_ratio=0.82, purchase_size=6, iva=0.0, lead_time=2, supplier="PROV-LAC", profile="staples"),
        _p("DC-LAC-002", "Yogur bebible 1 litro", "Lácteos", "botella", 2.30, 5.0, cost_ratio=0.72, purchase_size=2.5, lead_time=2, supplier="PROV-LAC"),
        _p("DC-LAC-003", "Queso fresco 500 g", "Lácteos", "unidad", 3.10, 4.0, cost_ratio=0.76, purchase_size=2, iva=0.0, lead_time=2, supplier="PROV-LAC"),
        _p("DC-LIM-001", "Detergente en polvo 1 kg", "Limpieza", "funda", 3.40, 8.0, cost_ratio=0.70, purchase_size=3, lead_time=5, supplier="PROV-LIM"),
        _p("DC-LIM-002", "Cloro 1 litro", "Limpieza", "botella", 0.90, 7.0, cost_ratio=0.62, purchase_size=4, lead_time=5, supplier="PROV-LIM"),
        _p("DC-LIM-003", "Jabón de lavar en barra", "Limpieza", "unidad", 0.75, 9.0, cost_ratio=0.64, purchase_size=6, lead_time=5, supplier="PROV-LIM"),
        _p("DC-LIM-004", "Lavavajillas en crema 500 g", "Limpieza", "unidad", 1.65, 5.5, cost_ratio=0.65, purchase_size=3, lead_time=5, supplier="PROV-LIM"),
        _p("DC-LIM-005", "Desinfectante multiuso 1 litro", "Limpieza", "botella", 2.05, 3.0, cost_ratio=0.62, purchase_size=2, lead_time=5, supplier="PROV-LIM"),
        _p("DC-CUI-001", "Papel higiénico (paquete 4)", "Cuidado personal", "paquete", 2.60, 10.0, cost_ratio=0.72, purchase_size=3, lead_time=5, supplier="PROV-LIM"),
        _p("DC-CUI-002", "Jabón de tocador 120 g", "Cuidado personal", "unidad", 0.85, 6.0, cost_ratio=0.62, purchase_size=4, lead_time=5, supplier="PROV-LIM"),
        _p("DC-CUI-003", "Shampoo 400 ml", "Cuidado personal", "botella", 4.20, 2.0, cost_ratio=0.62, purchase_size=1.5, lead_time=7, supplier="PROV-LIM"),
        _p("DC-CUI-004", "Pasta dental 100 ml", "Cuidado personal", "unidad", 1.90, 4.0, cost_ratio=0.60, purchase_size=3, lead_time=7, supplier="PROV-LIM"),
        _p("DC-CUI-005", "Toallas sanitarias (paquete 10)", "Cuidado personal", "paquete", 1.80, 3.0, cost_ratio=0.62, purchase_size=2, lead_time=7, supplier="PROV-LIM"),
        _p("DC-ESC-001", "Cuaderno universitario 100 hojas", "Útiles escolares", "unidad", 1.60, 1.0, cost_ratio=0.55, profile="school", purchase_size=4, lead_time=7, supplier="PROV-ESC"),
        _p("DC-ESC-002", "Lápices de grafito (caja 12)", "Útiles escolares", "caja", 2.20, 0.4, cost_ratio=0.52, profile="school", purchase_size=2, lead_time=7, supplier="PROV-ESC"),
        _p("DC-ESC-003", "Esferos azules (caja 12)", "Útiles escolares", "caja", 3.10, 0.35, cost_ratio=0.52, profile="school", purchase_size=2, lead_time=7, supplier="PROV-ESC"),
        _p("DC-ESC-004", "Mochila escolar básica", "Útiles escolares", "unidad", 14.50, 0.06, cost_ratio=0.58, profile="school", purchase_size=1, lead_time=14, supplier="PROV-ESC"),
        _p("DC-ESC-005", "Goma blanca 250 g", "Útiles escolares", "unidad", 1.40, 0.3, cost_ratio=0.50, profile="school", purchase_size=2, lead_time=7, supplier="PROV-ESC"),
        _p("DC-SNA-001", "Galletas surtidas (paquete 6)", "Snacks", "paquete", 1.50, 9.0, cost_ratio=0.66, profile="christmas_food", purchase_size=4, lead_time=4, supplier="PROV-ABA"),
        _p("DC-SNA-002", "Papas fritas 150 g", "Snacks", "funda", 1.30, 7.0, cost_ratio=0.62, purchase_size=4, lead_time=4, supplier="PROV-ABA"),
        _p("DC-SNA-003", "Chocolate en barra 100 g", "Snacks", "unidad", 1.75, 3.0, cost_ratio=0.62, profile="christmas_food", purchase_size=3, lead_time=5, supplier="PROV-ABA"),
        _p("DC-SNA-004", "Caramelos surtidos (funda 1 kg)", "Snacks", "funda", 4.80, 0.4, cost_ratio=0.60, profile="christmas_candy", purchase_size=2, lead_time=7, supplier="PROV-ABA"),
        _p("DC-HOG-001", "Fósforos (paquete 10 cajas)", "Hogar", "paquete", 0.90, 2.5, cost_ratio=0.55, purchase_size=2, lead_time=7, supplier="PROV-LIM"),
        _p("DC-HOG-002", "Velas blancas (paquete 6)", "Hogar", "paquete", 1.40, 0.5, cost_ratio=0.55, purchase_size=2, lead_time=7, supplier="PROV-LIM"),
        _p("DC-HOG-003", "Fundas de basura (paquete 10)", "Hogar", "paquete", 1.20, 4.0, cost_ratio=0.58, purchase_size=3, lead_time=7, supplier="PROV-LIM"),
        _p("DC-HOG-004", "Esponja de cocina (paquete 3)", "Hogar", "paquete", 1.10, 2.5, cost_ratio=0.50, purchase_size=2, lead_time=7, supplier="PROV-LIM"),
        _p("DC-HOG-005", "Ambientador en aerosol 400 ml", "Hogar", "unidad", 2.90, 0.9, cost_ratio=0.58, purchase_size=1.5, lead_time=7, supplier="PROV-LIM"),
    ],
    promotions=[
        PromoSpec("Regreso a clases Costa 2026", 0.10, "2026-04-01", "2026-04-30", category="Útiles escolares", lift=1.3),
        PromoSpec("Semana del ahorro en limpieza", 0.08, "2025-10-13", "2025-10-19", category="Limpieza", lift=1.3),
        PromoSpec("Semana del ahorro en limpieza 2026", 0.08, "2026-06-08", "2026-06-14", category="Limpieza", lift=1.3),
        PromoSpec("Navidad dulce 2025", 0.10, "2025-12-10", "2025-12-23", category="Snacks", lift=1.25),
        PromoSpec("Promo bebidas verano 2026", 0.07, "2026-02-02", "2026-02-15", category="Bebidas", lift=1.25),
        # Promoción futura conocida.
        PromoSpec("Navidad dulce 2026", 0.10, "2026-12-09", "2026-12-23", category="Snacks", lift=1.25),
    ],
)


COMPANIES = {c.slug: c for c in (FERRETERIA_ANDINA, DISTRIBUIDORA_COSTA)}
