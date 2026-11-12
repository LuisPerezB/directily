"""Extrae de train.csv (Favorita, 125 M de filas) las tiendas elegidas desde 2016-06-01 y lo guarda en parquet. Uso: python -I fav_extract.py <dir_datos> <salida.parquet>"""

import sys

import pyarrow as pa
import pyarrow.compute as cmp
import pyarrow.csv as pc
import pyarrow.parquet as pq

src, out = sys.argv[1], sys.argv[2]
STORES = pa.array([44, 51, 37, 22], pa.int32())  # Quito (A), Guayaquil (A), Cuenca (D), Puyo (C)
reader = pc.open_csv(
    f"{src}/train.csv",
    convert_options=pc.ConvertOptions(
        column_types={
            "id": pa.int64(),
            "date": pa.string(),
            "store_nbr": pa.int32(),
            "item_nbr": pa.int32(),
            "unit_sales": pa.float64(),
            "onpromotion": pa.string(),
        }
    ),
    read_options=pc.ReadOptions(block_size=64 << 20),
)
parts, n = [], 0
for batch in reader:
    n += batch.num_rows
    t = pa.Table.from_batches([batch])
    m = cmp.and_(cmp.greater_equal(t["date"], "2016-06-01"), cmp.is_in(t["store_nbr"], value_set=STORES))
    t = t.filter(m)
    if t.num_rows:
        parts.append(t.drop(["id"]))
tab = pa.concat_tables(parts)
pq.write_table(tab, out)
print("filas leídas", n, "filas guardadas", tab.num_rows)
