"""Vectores de firma compartidos con el backend (BridgeSignatureVectorsTest.php): mismo mensaje, misma firma (Ed25519 es determinista)."""

from __future__ import annotations

import json
from pathlib import Path

from predictily_bridge import crypto

V = json.loads((Path(__file__).parent / "fixtures" / "signature_vectors.json").read_text(encoding="utf-8"))


def test_keys_and_fingerprint() -> None:
    key = crypto.DeviceKey(crypto.b64u_decode(V["seed_b64"]))
    assert key.public_b64 == V["public_key_b64"]
    assert key.fingerprint == V["fingerprint"]


def test_request_messages_and_signatures_are_byte_identical_to_the_backends() -> None:
    key = crypto.DeviceKey(crypto.b64u_decode(V["seed_b64"]))
    for r in V["requests"]:
        message = crypto.request_message(r["method"], r["path"], r["query"], r["timestamp"], r["nonce"], r["body"].encode("utf-8"))
        assert message == r["message"]
        assert key.sign(message) == r["signature"]
        assert crypto.verify(key.public_bytes, r["signature"], message)
        assert not crypto.verify(key.public_bytes, r["signature"], message + "x")


def test_enrol_proof_matches() -> None:
    key = crypto.DeviceKey(crypto.b64u_decode(V["seed_b64"]))
    e = V["enrol"]
    message = crypto.enrol_message(e["code_hash"], key.public_b64, e["timestamp"])
    assert message == e["message"] and key.sign(message) == e["proof"]
