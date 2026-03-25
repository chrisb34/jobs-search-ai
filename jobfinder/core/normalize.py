"""Central normalization for raw jobs."""

from __future__ import annotations

import hashlib
import json
import re
import unicodedata


TITLE_STOPWORDS = {
    "a",
    "an",
    "and",
    "au",
    "cdi",
    "de",
    "des",
    "du",
    "en",
    "for",
    "f",
    "freelance",
    "full",
    "h",
    "hybrid",
    "internship",
    "intern",
    "job",
    "le",
    "les",
    "of",
    "or",
    "paris",
    "part",
    "remote",
    "stage",
    "the",
    "time",
}

COMPANY_STOPWORDS = {
    "company",
    "groupe",
    "group",
    "labs",
    "sas",
    "sasu",
    "sa",
    "inc",
    "llc",
    "ltd",
}


def _slugify(value: str | None) -> str:
    if not value:
        return ""
    normalized = unicodedata.normalize("NFKD", value)
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii")
    lowered = ascii_text.lower()
    lowered = re.sub(r"[^a-z0-9]+", "-", lowered).strip("-")
    return lowered


def _normalize_remote_type(raw_job: dict) -> str | None:
    location_type = raw_job.get("extra", {}).get("job_location_type")
    remote_raw = (raw_job.get("remote_raw") or "").lower()
    if location_type == "TELECOMMUTE" or "remote" in remote_raw:
        return "remote"
    if "hybrid" in remote_raw:
        return "hybrid"
    if remote_raw in {"fulltime", "punctual"}:
        return "remote"
    if remote_raw == "partial":
        return "hybrid"
    if remote_raw == "no":
        return "onsite"
    if remote_raw:
        return "onsite"
    return None


def _normalize_contract_type(value: object) -> str | None:
    if value is None:
        return None
    if isinstance(value, list):
        flattened = [str(item) for item in value if item is not None]
        return ", ".join(flattened) if flattened else None
    if isinstance(value, dict):
        return json.dumps(value, ensure_ascii=True, sort_keys=True)
    return str(value)


def _tokenize_signature(value: str | None) -> list[str]:
    if not value:
        return []
    normalized = _slugify(value)
    if not normalized:
        return []
    return [token for token in normalized.split("-") if token]


def _title_signature(raw_job: dict) -> str:
    tokens = _tokenize_signature(raw_job.get("title"))
    filtered = [token for token in tokens if token not in TITLE_STOPWORDS and len(token) > 1]
    if not filtered:
        filtered = tokens
    return "-".join(filtered[:8])


def _company_signature(raw_job: dict) -> str:
    tokens = _tokenize_signature(raw_job.get("company"))
    filtered = [token for token in tokens if token not in COMPANY_STOPWORDS]
    if not filtered:
        filtered = tokens
    return "-".join(filtered[:6])


def _location_signature(raw_job: dict) -> str:
    extra = raw_job.get("extra", {})
    city = extra.get("city")
    if city:
        return _slugify(city)
    remote_type = _normalize_remote_type(raw_job)
    if remote_type:
        return remote_type
    return _slugify(raw_job.get("location_raw"))


def _normalize_seniority(raw_job: dict) -> str | None:
    text = " ".join(
        filter(
            None,
            [
                raw_job.get("title"),
                raw_job.get("description_raw"),
                raw_job.get("extra", {}).get("seniority_level"),
            ],
        )
    ).lower()
    for label in ("staff", "principal", "lead", "senior", "mid", "junior", "intern"):
        if label in text:
            return label
    return None


def canonical_job_key(raw_job: dict) -> str:
    pieces = [
        _company_signature(raw_job),
        _title_signature(raw_job),
        _location_signature(raw_job),
    ]
    basis = "::".join(pieces)
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()[:24]


def normalize_raw_job(raw_job: dict) -> dict:
    extra = raw_job.get("extra", {})
    salary = extra.get("salary") or {}
    address = extra.get("job_location", {}) or {}
    country = extra.get("country")
    city = extra.get("city") or address.get("addressLocality")
    tech_stack = extra.get("skills")

    if isinstance(tech_stack, list):
        tech_stack_value = json.dumps(tech_stack, ensure_ascii=True)
    else:
        tech_stack_value = tech_stack

    return {
        "source": raw_job["source"],
        "source_job_id": raw_job["source_job_id"],
        "canonical_job_key": canonical_job_key(raw_job),
        "title_normalized": _slugify(raw_job.get("title")) or None,
        "company_normalized": _slugify(raw_job.get("company")) or None,
        "country": country,
        "city": city,
        "remote_type": _normalize_remote_type(raw_job),
        "contract_type": _normalize_contract_type(raw_job.get("contract_raw")),
        "salary_currency": salary.get("currency"),
        "salary_min": salary.get("min"),
        "salary_max": salary.get("max"),
        "seniority": _normalize_seniority(raw_job),
        "language": extra.get("language"),
        "tech_stack": tech_stack_value,
        "ai_score": None,
        "ai_reason": None,
        "ai_decision": None,
    }
