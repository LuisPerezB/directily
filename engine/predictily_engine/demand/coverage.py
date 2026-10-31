"""Completitud declarada de la fuente de ventas (ADR-V2-05, instrucctions.md §6.C).

`data_coverage` (entidad `sales`) declara hasta qué fecha (`complete_through`) y desde cuál (`complete_from`)
la fuente está completa, por empresa o por sucursal. La cobertura EFECTIVA de cada ámbito la resuelve el core de
Datos (`extensions.data_core.effective_coverage`: declaración del usuario > mínimo entre fuentes > ninguna) y aquí
solo se aplica a la demanda:

- El corte NO supera la menor `complete_through` efectiva (empresa o sucursales activas): un día posterior
  no es un cero observado sino un dato AUSENTE, de modo que no se rellena con ceros ni se entrena con él.
- Las ventas anteriores a `complete_from` se descartan: la serie empieza en la primera venta completa.
- Sin ningún registro con fechas se conserva el comportamiento de la etapa 1 (ceros hasta la última venta)
  y se añade la limitación `coverage_undeclared`: la completitud de la fuente no está verificada.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import TYPE_CHECKING, Any

import pandas as pd

if TYPE_CHECKING:  # pragma: no cover
    from predictily_engine.pipeline.data import TenantData

LIMITATION_UNDECLARED = {
    "code": "coverage_undeclared",
    "message": ("No hay cobertura declarada de la fuente de ventas (data_coverage): los días sin venta hasta el corte se "
                "tratan como cero observado, pero la completitud de la fuente no está verificada."),
}


def _d(v: Any) -> date | None:
    if v is None or (isinstance(v, float) and pd.isna(v)) or v is pd.NaT:
        return None
    return pd.Timestamp(v).date()


@dataclass
class SalesCoverage:
    declared: bool = False  # hay cobertura registrada (declarada por el usuario o inferida por un conector)
    rule: str | None = None  # regla de la cobertura efectiva de la empresa (declared_by_user | min_over_sources | none)
    basis: str | None = None  # declared | inferred | None
    through: date | None = None  # menor complete_through efectivo (empresa y sucursales activas)
    from_: date | None = None  # mayor complete_from de la empresa
    branch_from: dict[int, date] = field(default_factory=dict)
    sources: list[dict[str, Any]] = field(default_factory=list)
    requested_cutoff: date | None = None
    effective_cutoff: date | None = None
    dropped_before_from: int = 0

    @classmethod
    def from_effective(cls, company: dict[str, Any], branches: dict[int, dict[str, Any]] | None = None,
                       rows: pd.DataFrame | None = None) -> "SalesCoverage":
        """`company`: cobertura efectiva de toda la empresa (`effective_coverage(conn, tenant, 'sales', None)`);
        `branches`: la efectiva de cada sucursal ACTIVA con cobertura propia (una sucursal cerrada declarada no fija
        el corte); `rows`: filas crudas de `data_coverage` (solo para auditar las fuentes usadas)."""
        cov = cls()
        cov.rule, cov.basis = company.get("rule"), company.get("basis")
        throughs: list[date] = []
        t, f = _d(company.get("complete_through")), _d(company.get("complete_from"))
        if t is not None:
            throughs.append(t)
        cov.from_ = f
        for b, eff in (branches or {}).items():
            bt, bf = _d(eff.get("complete_through")), _d(eff.get("complete_from"))
            if bt is not None:
                throughs.append(bt)
            if bf is not None and bf != f:
                cov.branch_from[int(b)] = bf
        if rows is not None and not rows.empty:
            for r in rows.to_dict("records"):
                branch = r.get("branch_id")
                source = r.get("data_source_id")
                cov.sources.append({
                    "data_source_id": None if source is None or pd.isna(source) else int(source),
                    "branch_id": None if branch is None or pd.isna(branch) else int(branch),
                    "complete_from": (_d(r.get("complete_from")) or None) and _d(r.get("complete_from")).isoformat(),
                    "complete_through": (_d(r.get("complete_through")) or None) and _d(r.get("complete_through")).isoformat(),
                    "basis": r.get("basis")})
        cov.declared = bool(throughs) or cov.from_ is not None or bool(cov.branch_from)
        cov.through = min(throughs) if throughs else None
        return cov

    @classmethod
    def resolve(cls, conn: Any, tenant_id: int, rows: pd.DataFrame, active_branches: set[int] | None = None) -> "SalesCoverage":
        """Cobertura efectiva de ventas de la empresa a partir de `data_coverage` (todas las lecturas filtran por empresa).
        `active_branches`: solo ellas pueden fijar el corte con una cobertura propia; None = todas."""
        from predictily_engine.extensions.data_core import effective_coverage

        own = set(rows["branch_id"].dropna().astype(int)) if rows is not None and not rows.empty else set()
        if active_branches is not None:
            own &= active_branches
        return cls.from_effective(effective_coverage(conn, tenant_id, "sales", None),
                                  {b: effective_coverage(conn, tenant_id, "sales", b) for b in sorted(own)}, rows)

    def limit_cutoff(self, cutoff: date) -> date:
        self.requested_cutoff = cutoff
        self.effective_cutoff = min(cutoff, self.through) if self.through is not None else cutoff
        return self.effective_cutoff

    @property
    def cutoff_limited(self) -> bool:
        return (self.requested_cutoff is not None and self.effective_cutoff is not None
                and self.effective_cutoff < self.requested_cutoff)

    def apply(self, data: "TenantData") -> "TenantData":
        """Descarta ventas (y devoluciones) anteriores a `complete_from`: no son un histórico completo."""
        dropped = 0
        for name in ("sales", "returns"):
            df = getattr(data, name)
            if df is None or df.empty:
                continue
            keep = pd.Series(True, index=df.index)
            if self.from_ is not None:
                keep &= df["ds"] >= pd.Timestamp(self.from_)
            for b, f in self.branch_from.items():
                keep &= ~((df["branch_id"] == b) & (df["ds"] < pd.Timestamp(f)))
            if not keep.all():
                if name == "sales":
                    dropped += int((~keep).sum())
                setattr(data, name, df[keep].reset_index(drop=True))
        self.dropped_before_from = dropped
        data.coverage = self.as_dict()
        return data

    def limitations(self) -> list[dict[str, str]]:
        out: list[dict[str, str]] = []
        if not self.declared or self.through is None:
            out.append(LIMITATION_UNDECLARED)
        if self.cutoff_limited:
            out.append({"code": "cutoff_limited_by_coverage",
                        "message": (f"El corte se limitó a {self.effective_cutoff} (cobertura completa declarada de la "
                                    f"fuente de ventas): los días posteriores son datos ausentes, no ceros.")})
        if self.dropped_before_from:
            out.append({"code": "sales_before_complete_from_ignored",
                        "message": f"Se ignoraron {self.dropped_before_from} filas de ventas anteriores a la cobertura completa."})
        return out

    def as_dict(self) -> dict[str, Any]:
        return {
            "sales": {
                "declared": self.declared, "basis": self.basis if self.declared else "undeclared", "rule": self.rule,
                "complete_through": self.through.isoformat() if self.through else None,
                "complete_from": self.from_.isoformat() if self.from_ else None,
                "branch_complete_from": {str(k): v.isoformat() for k, v in sorted(self.branch_from.items())},
                "requested_cutoff": self.requested_cutoff.isoformat() if self.requested_cutoff else None,
                "effective_cutoff": self.effective_cutoff.isoformat() if self.effective_cutoff else None,
                "cutoff_limited": self.cutoff_limited, "rows_dropped_before_complete_from": self.dropped_before_from,
                "sources": self.sources,
            }
        }
