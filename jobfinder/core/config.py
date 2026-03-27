"""Helpers for loading saved search configuration."""

from __future__ import annotations

from pathlib import Path

import yaml


def resolve_local_override_path(path: str | Path) -> Path:
    base_path = Path(path)
    local_path = base_path.with_name(f"{base_path.stem}.local{base_path.suffix}")
    return local_path if local_path.exists() else base_path


def load_yaml_config(path: str | Path) -> dict:
    resolved_path = resolve_local_override_path(path)
    with open(resolved_path, "r", encoding="utf-8") as handle:
        data = yaml.safe_load(handle) or {}
    if not isinstance(data, dict):
        raise ValueError("Config root must be a mapping")
    return data


def load_sources_config(path: str | Path) -> dict:
    data = load_yaml_config(path)
    searches = data.get("searches")
    if not isinstance(searches, list) or not searches:
        raise ValueError("Config must define a non-empty 'searches' list")
    return data
