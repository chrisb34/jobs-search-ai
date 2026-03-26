"""LLM-based second-pass scoring for jobs that pass rule-based filtering."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass

import requests


@dataclass
class LLMScoreResult:
    score: float
    decision: str
    reason: str
    model: str
    usage: dict | None


class LLMScoringError(RuntimeError):
    """Raised when the LLM scoring request fails."""


def score_job_with_llm(job: dict, *, criteria: dict) -> LLMScoreResult:
    api_key = os.environ.get("OPENAI_API_KEY", "").strip()
    model = os.environ.get("OPENAI_MODEL", "gpt-5-mini").strip() or "gpt-5-mini"
    base_url = os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1").rstrip("/")

    if not api_key:
        raise LLMScoringError("Missing OPENAI_API_KEY in the environment.")

    payload = {
        "model": model,
        "input": _build_prompt(job, criteria),
    }

    response = requests.post(
        f"{base_url}/responses",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
        json=payload,
        timeout=90,
    )

    if not response.ok:
        message = _extract_error_message(response)
        raise LLMScoringError(f"OpenAI request failed: {message}")

    parsed = response.json()
    content = _extract_text(parsed)

    try:
        result = json.loads(content)
    except json.JSONDecodeError as exc:
        raise LLMScoringError("OpenAI returned non-JSON scoring output.") from exc

    score = float(result.get("score", 0))
    decision = str(result.get("decision", "reject")).strip().lower()
    if decision not in {"high", "maybe", "reject"}:
        decision = "reject"

    reason = str(result.get("reason", "")).strip()
    if not reason:
        red_flags = result.get("red_flags") or []
        matched_strengths = result.get("matched_strengths") or []
        reason = _fallback_reason(red_flags, matched_strengths)

    return LLMScoreResult(
        score=round(score, 2),
        decision=decision,
        reason=reason[:2000],
        model=parsed.get("model", model),
        usage=parsed.get("usage"),
    )


def _build_prompt(job: dict, criteria: dict) -> str:
    desired = criteria.get("desired", {})
    excluded = criteria.get("excluded", {})

    return f"""
You are rescoring a job listing for candidate fit.

Your task:
- Judge whether this role is a good fit for an experienced backend / platform / API-focused engineer.
- Be strict about primary stack fit, business domain fit, and role scope.
- Penalize jobs that are mainly frontend, ML/AI, data science, gaming, crypto, sales, or otherwise clearly outside that profile.
- Penalize jobs where the advert emphasizes technologies the candidate likely does not know deeply, even if the title sounds relevant.
- Use the rule-based score as a weak hint only. Override it when the description shows the job is a poor fit.

Desired title signals: {_stringify(desired.get("title_keywords", []))}
Desired tech signals: {_stringify(desired.get("tech_keywords", []))}
Preferred seniority: {_stringify(desired.get("seniority", []))}
Preferred remote types: {_stringify(desired.get("remote_types", []))}
Excluded keywords: {_stringify(excluded.get("keywords", []))}

Job data:
Source: {job.get("source")}
Title: {job.get("title")}
Company: {job.get("company")}
Location: {job.get("location_raw")}
Remote type: {job.get("remote_type")}
Contract type: {job.get("contract_type") or job.get("contract_raw")}
Country: {job.get("country")}
City: {job.get("city")}
Language: {job.get("language")}
Seniority: {job.get("seniority")}
Rule score: {job.get("ai_score")}
Rule decision: {job.get("ai_decision")}
Rule reason: {job.get("ai_reason")}
Salary: {job.get("salary_raw")}
URL: {job.get("url")}

Description:
{job.get("description_raw") or ""}

Return JSON only in this exact shape:
{{
  "score": 0,
  "decision": "high|maybe|reject",
  "reason": "short explanation",
  "red_flags": ["optional", "optional"],
  "matched_strengths": ["optional", "optional"]
}}

Scoring guidance:
- 80-100: strong, obvious fit worth immediate review
- 55-79: plausible fit with some gaps
- 0-54: weak fit or misleading keyword match

Keep the reason concise and specific. Do not use markdown fences.
""".strip()


def _stringify(values: list[object]) -> str:
    return ", ".join(str(value) for value in values if str(value).strip()) or "none"


def _extract_text(response: dict) -> str:
    direct_text = str(response.get("output_text") or "").strip()
    if direct_text:
        return direct_text

    parts: list[str] = []
    for output_item in response.get("output", []):
        for content_item in output_item.get("content", []):
            text = str(content_item.get("text") or "").strip()
            if text:
                parts.append(text)

    joined = "\n\n".join(parts).strip()
    if not joined:
        raise LLMScoringError("OpenAI returned no usable text.")
    return joined


def _extract_error_message(response: requests.Response) -> str:
    try:
        payload = response.json()
    except ValueError:
        return f"status {response.status_code}"
    message = payload.get("error", {}).get("message")
    if isinstance(message, str) and message.strip():
        return message
    return f"status {response.status_code}"


def _fallback_reason(red_flags: list[object], matched_strengths: list[object]) -> str:
    flags = [str(item).strip() for item in red_flags if str(item).strip()]
    strengths = [str(item).strip() for item in matched_strengths if str(item).strip()]
    parts: list[str] = []
    if strengths:
        parts.append(f"matched: {', '.join(strengths[:3])}")
    if flags:
        parts.append(f"red flags: {', '.join(flags[:3])}")
    return "; ".join(parts) or "No reason provided."
