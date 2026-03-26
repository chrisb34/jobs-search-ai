"""Apply an LLM second-pass scoring to already-scored jobs."""

from __future__ import annotations

import argparse
from pathlib import Path

from jobfinder.core.config import load_yaml_config
from jobfinder.core.llm_scoring import LLMScoringError, score_job_with_llm
from jobfinder.core.storage import (
    connect,
    init_db,
    query_jobs_for_llm_scoring,
    update_job_llm_score,
)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="LLM rescore promising jobs.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--criteria", default="config/criteria.yaml", help="Scoring criteria YAML path")
    parser.add_argument("--status", default="active", help="Raw job lifecycle status filter")
    parser.add_argument("--limit", type=int, default=50, help="Max jobs to score")
    parser.add_argument(
        "--min-rule-score",
        type=float,
        default=35.0,
        help="Only send jobs with rule-based ai_score >= this threshold",
    )
    parser.add_argument(
        "--only-unscored",
        action="store_true",
        help="Only score jobs that do not already have ai_llm_decision",
    )
    parser.add_argument(
        "--shortlist-status",
        default="new",
        help="Only score jobs in interesting_jobs with this shortlist status; use 'any' to disable",
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    criteria = load_yaml_config(args.criteria)
    scored = 0

    with connect(Path(args.db_path)) as conn:
        init_db(conn)
        rows = query_jobs_for_llm_scoring(
            conn,
            status=args.status,
            limit=args.limit,
            min_rule_score=args.min_rule_score,
            only_unscored=args.only_unscored,
            shortlist_status=None if args.shortlist_status == "any" else args.shortlist_status,
        )

        for row in rows:
            result = score_job_with_llm(dict(row), criteria=criteria)
            update_job_llm_score(
                conn,
                source=row["source"],
                source_job_id=row["source_job_id"],
                score=result.score,
                decision=result.decision,
                reason=result.reason,
                model=result.model,
                usage=result.usage,
            )
            scored += 1

    print(
        f"LLM-scored {scored} job(s) with rule score >= {args.min_rule_score:g}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except LLMScoringError as exc:
        print(str(exc))
        raise SystemExit(1)
