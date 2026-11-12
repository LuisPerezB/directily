"""Corre el banco de pruebas de factores sobre una base pública. Uso:

python run_tests.py favorita <corrida_favorita_2015.pkl> <dir_noaa>
python run_tests.py m5 <dir_m5>
python run_tests.py rossmann <dir_rossmann>
"""

import json
import sys

import datasets
import harness

name = sys.argv[1]
ds = getattr(datasets, name)(*sys.argv[2:])
result = harness.run(ds)
print("JSON " + json.dumps(result, ensure_ascii=False))
