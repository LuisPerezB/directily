import sys
import json
import time
import warnings
from pathlib import Path

from _validation_context import validation_context

SOURCE, OUTPUT = validation_context()
warnings.filterwarnings('ignore')
from predictily_engine.synthetic import generate_company
from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.pipeline.core import run_pipeline

t0 = time.perf_counter()
company = generate_company('ferreteria_andina', seed=42)
data = tenant_data_from_synthetic(company)
result = run_pipeline(data, horizon=90, seed=42, n_jobs=4)
out = {'data_kind':'synthetic_generated_seed_42', 'company':'ferreteria_andina',
       'sales_rows':len(company.sales), 'cutoff':str(result.cutoff),
       'series':len(result.panel.profile), 'summary':result.summary,
       'total_in_memory_seconds':round(time.perf_counter()-t0,2)}
(OUTPUT / 'benchmark-results.json').write_text(
    json.dumps(out,ensure_ascii=False,indent=2,default=str),encoding='utf-8')
print(json.dumps(out,ensure_ascii=False,indent=2,default=str))
