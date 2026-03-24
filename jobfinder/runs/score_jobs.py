"""Apply rule-based scoring to normalized jobs."""

from __future__ import annotations

import argparse
from pathlib import Path

from jobfinder.core.config import load_yaml_config
from jobfinder.core.scoring import score_job
from jobfinder.core.storage import connect, query_jobs_for_scoring, update_job_score


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Score normalized jobs using local rules.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--criteria", default="config/criteria.yaml", help="Scoring criteria YAML path")
    parser.add_argument("--status", default="active", help="Raw job lifecycle status filter")
    parser.add_argument("--limit", type=int, default=200, help="Max jobs to score")
    parser.add_argument(
        "--only-unscored",
        action="store_true",
        help="Only score jobs that do not already have ai_decision",
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    criteria = load_yaml_config(args.criteria)

    with connect(Path(args.db_path)) as conn:
        rows = query_jobs_for_scoring(
            conn,
            status=args.status,
            limit=args.limit,
            only_unscored=args.only_unscored,
        )
        for row in rows:
            result = score_job(dict(row), criteria)
            update_job_score(
                conn,
                source=row["source"],
                source_job_id=row["source_job_id"],
                score=result.score,
                decision=result.decision,
                reason="; ".join(result.reasons),
            )

    print(f"Scored {len(rows)} job(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
