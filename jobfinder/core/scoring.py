"""Rule-based scoring for normalized jobs."""

from __future__ import annotations

import json
from dataclasses import dataclass


@dataclass
class ScoreResult:
    score: float
    decision: str
    reasons: list[str]


def load_job_text(job: dict) -> str:
    parts = [
        job.get("title") or "",
        job.get("title_normalized") or "",
        job.get("company") or "",
        job.get("company_normalized") or "",
        job.get("location_raw") or "",
        job.get("city") or "",
        job.get("country") or "",
        job.get("remote_type") or "",
        job.get("contract_type") or "",
        job.get("seniority") or "",
        job.get("description_raw") or "",
    ]
    tech_stack = job.get("tech_stack")
    if tech_stack:
        parts.append(_stringify_tech_stack(tech_stack))
    return " ".join(part for part in parts if part).lower()


def score_job(job: dict, criteria: dict) -> ScoreResult:
    text = load_job_text(job)
    score = 0.0
    reasons: list[str] = []

    desired = criteria.get("desired", {})
    excluded = criteria.get("excluded", {})
    thresholds = criteria.get("thresholds", {})

    title_score = _keyword_score(
        text,
        desired.get("title_keywords", []),
        desired.get("title_keyword_weight", 12),
        "title keyword",
    )
    score += title_score.score
    reasons.extend(title_score.reasons)

    stack_score = _keyword_score(
        text,
        desired.get("tech_keywords", []),
        desired.get("tech_keyword_weight", 6),
        "tech keyword",
    )
    score += stack_score.score
    reasons.extend(stack_score.reasons)

    seniority = (job.get("seniority") or "").lower()
    preferred_seniority = {value.lower() for value in desired.get("seniority", [])}
    if seniority and seniority in preferred_seniority:
        score += desired.get("seniority_weight", 10)
        reasons.append(f"matched seniority: {seniority}")

    remote_type = (job.get("remote_type") or "").lower()
    preferred_remote = {value.lower() for value in desired.get("remote_types", [])}
    if remote_type and remote_type in preferred_remote:
        score += desired.get("remote_weight", 8)
        reasons.append(f"matched remote type: {remote_type}")

    contract_type = (job.get("contract_type") or "").lower()
    preferred_contracts = {value.lower() for value in desired.get("contract_types", [])}
    if contract_type and contract_type in preferred_contracts:
        score += desired.get("contract_weight", 4)
        reasons.append(f"matched contract type: {job.get('contract_type')}")

    location_tokens = {
        value.lower()
        for value in (
            desired.get("countries", [])
            + desired.get("cities", [])
            + desired.get("location_keywords", [])
        )
    }
    matched_locations = [token for token in location_tokens if token and token in text]
    if matched_locations:
        score += desired.get("location_weight", 5)
        reasons.append(f"matched location: {matched_locations[0]}")

    for keyword in excluded.get("keywords", []):
        if keyword.lower() in text:
            score -= excluded.get("keyword_penalty", 20)
            reasons.append(f"excluded keyword: {keyword}")

    if seniority:
        excluded_seniority = {value.lower() for value in excluded.get("seniority", [])}
        if seniority in excluded_seniority:
            score -= excluded.get("seniority_penalty", 25)
            reasons.append(f"excluded seniority: {seniority}")

    min_score_high = thresholds.get("high", 35)
    min_score_maybe = thresholds.get("maybe", 20)
    decision = "reject"
    if score >= min_score_high:
        decision = "high"
    elif score >= min_score_maybe:
        decision = "maybe"

    return ScoreResult(
        score=round(score, 2),
        decision=decision,
        reasons=reasons[:8],
    )


def _keyword_score(text: str, keywords: list[str], weight: float, label: str) -> ScoreResult:
    score = 0.0
    reasons: list[str] = []
    for keyword in keywords:
        if keyword.lower() in text:
            score += weight
            reasons.append(f"matched {label}: {keyword}")
    return ScoreResult(score=score, decision="", reasons=reasons)


def _stringify_tech_stack(value: str) -> str:
    try:
        parsed = json.loads(value)
    except (TypeError, json.JSONDecodeError):
        return str(value)
    if isinstance(parsed, list):
        return " ".join(str(item) for item in parsed)
    return str(parsed)
