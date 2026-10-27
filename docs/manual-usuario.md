# Manual básico de uso — Predictily

> **Anticipa tus ventas. Optimiza tus decisiones.**
> Este manual explica, pantalla por pantalla y sin tecnicismos, cómo usar Predictily.

---

## 1. Qué es Predictily (y qué no es)

Predictily toma las ventas que usted ya registra en su sistema de facturación, POS o ERP y calcula:

- cuánto se estima que venderá de cada producto, en cada sucursal, en los próximos 7, 30 o 90 días;
- qué productos podrían agotarse y cuánto convendría comprar;
- qué tan acertados han sido los pronósticos anteriores.

**No reemplaza** su sistema de facturación ni su contabilidad: solo lee sus datos. Tampoco hace pedidos ni
compromete dinero por usted: todas las compras sugeridas son recomendaciones que usted decide.

Los pronósticos se calculan con modelos estadísticos y de *machine learning* que se prueban contra su propio
historial. Predictily siempre los compara con un método sencillo de referencia y solo usa un modelo más complejo
si demostró equivocarse menos.

---

## 2. Crear su cuenta y su empresa

1. En la página principal pulse **Crear cuenta gratis**, complete nombre, correo y contraseña (mínimo 8
   caracteres) y acepte los términos.
2. Luego cree su **empresa**: nombre comercial, razón social y RUC (opcionales), sector y zona horaria
   (Ecuador continental por defecto). La moneda es el dólar (USD).
3. Usted queda como **Propietario** de la empresa. Puede pertenecer a varias empresas y cambiar entre ellas con
   el selector de la parte superior.

## 3. Primeros pasos

La pantalla **Primeros pasos** muestra una lista de verificación:

| Paso | Para qué sirve |
|---|---|
| Registrar sucursales | Use el mismo código de establecimiento de sus facturas (por ejemplo `001`). |
| Importar ventas | Es lo único indispensable. Idealmente 12 meses o más. |
| Importar productos | Opcional si sus ventas ya traen código y nombre del producto. |
| Importar inventario | Opcional, pero necesario para alertas de quiebre de stock y compras sugeridas. |
| Generar el primer pronóstico | Entrena y evalúa los modelos con sus datos. |

**¿Quiere probar antes?** Si su empresa aún no tiene ventas, el botón **Cargar datos de demostración
(ficticios)** carga datos de un comercio inventado. Esos datos **no corresponden a ninguna empresa real**.

## 4. Importar datos (asistente)

Menú **Importar datos**. El asistente tiene cuatro pasos:

1. **Archivo**: elija qué datos importa (ventas, productos, inventario, plazos de proveedores, sucursales,
   proveedores o promociones) y suba un archivo **CSV o Excel (.xlsx)** de hasta 20 MB. Puede descargar una
   plantilla con las columnas esperadas.
2. **Columnas**: Predictily sugiere qué columna de su archivo corresponde a cada dato (por ejemplo, su columna
   «PVP» → *Precio unitario*). Revise las sugerencias, corrija lo necesario y ajuste las opciones de lectura
   (formato de fecha, separador decimal, sucursal por defecto, crear productos nuevos automáticamente…).
   Los campos con asterisco son obligatorios.
3. **Revisión**: Predictily valida todo el archivo y muestra:
   - filas leídas, válidas, con error y duplicadas;
   - documentos nuevos, que se actualizarán y sin cambios;
   - facturas anuladas (se guardan pero no cuentan como venta) y devoluciones (se registran aparte);
   - un **reporte de calidad** y la lista de errores por fila, descargable en CSV.

   Si hay errores puede **corregir el mapeo u opciones** y volver a validar, corregir el archivo y subirlo de
   nuevo, o activar *Importar solo filas válidas*. Si algo no le convence, **Rechazar** descarta todo.
4. **Importado**: confirmación con el resumen.

**Reimportar no duplica.** Cada documento se identifica por su tipo y número (por ejemplo factura
`001-001-000012345`). Si vuelve a subir el mismo archivo, o uno actualizado, los documentos existentes no se
duplican; los que cambiaron (por ejemplo una factura anulada después) se actualizan.

El historial de importaciones está en **Fuentes de datos**.

## 5. Dashboard comercial

Responde seis preguntas para el horizonte elegido (7, 30 o 90 días) y la sucursal seleccionada:

| Pregunta | Dónde verlo |
|---|---|
| ¿Cuánto se estima que venderé? | Tarjeta de unidades estimadas, con su **rango probable (80 %)** y la variación frente al período anterior. |
| ¿Cuánto podría facturar? | Ingresos estimados, suponiendo los precios recientes. |
| ¿Qué productos cambiarán su demanda? | Listas de mayor aumento y mayor disminución esperada. |
| ¿Qué productos podrían agotarse? | Tarjeta de riesgo y tabla de productos con días de cobertura y probabilidad de quiebre. |
| ¿Cuánto inventario necesito? | Cobertura mediana del inventario y cantidad sugerida por producto. |
| ¿Cuánto dinero inmovilizaría en compras? | Compra sugerida estimada (cantidad sugerida × último costo conocido). |
| ¿Qué tan confiables han sido las predicciones? | Error histórico del modelo frente al método de referencia. |

Desde el dashboard también puede **actualizar el pronóstico** cuando haya cargado ventas nuevas.

## 6. Pronósticos de demanda

Muestra el **histórico de ventas** (línea continua) y el **pronóstico** (línea punteada) para la empresa, una
sucursal, una categoría o un producto. Puede ver por día, semana o mes, en unidades o en ingresos.

**Cómo leer las bandas:**

- La línea punteada es el **valor más probable**.
- La **banda oscura (80 %)**: según la evaluación histórica, la venta real cae dentro de ella aproximadamente
  8 de cada 10 veces.
- La **banda clara (95 %)**: aproximadamente 95 de cada 100 veces.
- Una banda ancha significa más incertidumbre (por ejemplo, productos de venta esporádica). No es un error:
  es información útil para decidir cuánto stock de seguridad tener.
- Los puntos rojos en el histórico marcan días con **quiebre de stock**: ese día la venta pudo ser menor que
  la demanda real porque no había producto.

Cada pronóstico indica su **versión**, la **fecha de generación**, la **fecha de corte** (hasta qué día de
datos se usó), el **modelo** y el **supuesto de precio**. Las versiones anteriores se conservan; puede elegir
cualquiera en el selector *Versión*.

**Generar un nuevo pronóstico:** pulse *Generar nuevo pronóstico*. El proceso corre en segundo plano (puede
tardar algunos minutos) y puede seguir usando Predictily mientras tanto; verá el avance por etapas.

## 7. Productos y categorías

Tabla con, para cada producto o categoría: unidades vendidas en el período previo, unidades pronosticadas
(con su rango del 80 %), el cambio esperado, ingresos estimados, el modelo usado, el **patrón de demanda**
(regular, errática, intermitente…) y su error histórico. Puede filtrar, ordenar, buscar y **exportar a CSV**.

## 8. Inventario y abastecimiento

Para cada producto y sucursal muestra existencias disponibles (existencias − reservado + en tránsito), días de
cobertura, probabilidad de quiebre, punto de reorden, **compra sugerida** e inversión estimada.

Al abrir un producto verá **cómo se calculó**: demanda esperada durante el plazo de entrega más el período de
revisión, stock de seguridad según su nivel de servicio, y compra sugerida = punto de reorden − disponible
(nunca negativa). Los parámetros (nivel de servicio, período de revisión, plazo por defecto) se ajustan en
**Configuración › Parámetros**.

Puede marcar cada recomendación como *revisada*, *pedido registrado* o *descartada*. Esto solo deja registro:
**Predictily no envía pedidos a proveedores**.

## 9. Historial de precisión

Aquí se mide qué tan acertados han sido los pronósticos, siempre con **sus propios datos**:

- **Backtesting**: Predictily simula pronósticos en fechas pasadas, usando solo la información disponible en
  ese momento, y los compara con lo que realmente se vendió.
- **Error real**: cuando pasan los días, compara los pronósticos ya emitidos con las ventas que ocurrieron.

**Cómo interpretar las métricas:**

| Métrica | Qué significa | ¿Mejor? |
|---|---|---|
| **WAPE** | Error total en relación con lo vendido. 20 % = en conjunto, el pronóstico se desvió el 20 % de lo vendido. | Menor |
| **MAE** | Diferencia promedio en unidades entre lo pronosticado y lo vendido. | Menor |
| **Sesgo** | Positivo: tiende a pronosticar de más. Negativo: de menos. | Cercano a 0 |
| **Cobertura 80 %** | Veces que la venta real cayó dentro del rango del 80 %. | Cercano a 80 % |
| **Método de referencia** | Método simple (por ejemplo repetir la misma semana anterior). El modelo elegido debería equivocarse menos. | — |

Predictily **no** muestra un «porcentaje de precisión» comercial: muestra errores medidos. Es normal que el
error aumente cuanto más lejos se pronostica y que los productos de venta esporádica tengan errores mayores.

La sección *Comparar real vs. pronosticado* permite elegir una versión pasada y ver, día a día, el pronóstico
frente a la venta real.

## 10. Simulador de escenarios

Permite explorar preguntas del tipo **«¿qué pasaría si…?»**: cambiar el precio, aplicar un descuento, un
aumento de costo del proveedor, un cambio de demanda esperado, el plazo de abastecimiento o el presupuesto de
compras. Muestra la base y el escenario lado a lado (unidades, ingresos, costo, margen bruto, compra necesaria),
las diferencias y los riesgos (margen negativo, presupuesto insuficiente, costos desconocidos…).

**Importante:** es una **simulación con sus supuestos**, no una predicción ni una estimación causal.
Si baja el precio, el simulador **no supone** que venderá más; si usted espera ese efecto, ingréselo en
*Cambio de demanda esperado*. La medición del efecto real de los precios sobre la demanda (elasticidad) es una
función futura que requiere más evidencia.

Puede **guardar** escenarios para revisarlos después.

## 11. Fuentes de datos

- **Archivos (Excel/CSV)**: cargas periódicas con el asistente de importación.
- **API REST**: su sistema puede enviar ventas automáticamente. Cree una fuente *API REST* y luego un
  **token**; se muestra **una sola vez**, guárdelo en un lugar seguro.
- **Conectores**: integraciones con sistemas específicos. Los marcados como *Planificado* aún no están activos
  y requerirán credenciales del proveedor.

También verá aquí el **historial de importaciones**.

## 12. Alertas

Avisos sobre riesgo de quiebre de stock, deterioro de la precisión de los pronósticos, calidad de datos e
importaciones o pronósticos fallidos. Puede marcarlas como vistas o resueltas. El contador de la campana muestra
las alertas abiertas.

## 13. Configuración

| Pestaña | Contenido |
|---|---|
| Empresa | Nombre, razón social, RUC, sector, zona horaria. |
| Parámetros | Nivel de servicio, período de revisión, plazo de entrega por defecto, umbral de alerta de quiebre, actualización automática del pronóstico (diaria, semanal o manual) y horizonte por defecto. |
| Sucursales | Alta, edición y baja de sucursales. |
| Usuarios | Invitar personas y asignar roles. |
| Privacidad y datos | Consentimiento de entrenamiento compartido, exportación completa de datos y solicitud de eliminación de la empresa. |
| Plan y consumo | Plan actual, límites y consumo del mes. |
| Auditoría | Registro de accesos y acciones importantes. |

**Roles:**

| Rol | Puede |
|---|---|
| Propietario | Todo, incluidos consentimientos, exportación completa y eliminación de la empresa. |
| Administrador | Usuarios, sucursales, fuentes de datos y configuración. |
| Analista comercial | Importar datos, generar pronósticos, simular escenarios y exportar reportes. |
| Usuario de consulta | Solo ver dashboards, pronósticos y alertas. |

**Privacidad.** Sus datos están aislados de los de otras empresas. Por defecto solo se usan para sus propios
pronósticos. El *entrenamiento compartido* entre empresas es voluntario, requiere autorización expresa del
propietario y puede revocarse. Predictily aplica la Ley Orgánica de Protección de Datos Personales del Ecuador:
puede exportar todos sus datos y solicitar su eliminación (con un período de gracia de 30 días).

## 14. Preguntas frecuentes

**¿Cuánta historia necesito?** Con pocas semanas Predictily usa métodos simples (promedios, estacionales). Con
12 meses o más puede capturar temporadas como décimos, regreso a clases o Navidad.

**¿Por qué un producto tiene un rango muy amplio?** Porque su venta es irregular o esporádica. El rango refleja
la incertidumbre real; úselo para decidir el stock de seguridad.

**¿Cada factura nueva reentrena el modelo?** No. Los modelos se actualizan según la programación elegida
(diaria o semanal) o cuando usted lo solicita.

**¿Puedo confiar en el pronóstico?** Revise el **Historial de precisión**: allí ve el error medido con sus
propios datos y la comparación con el método de referencia.
