"""Promote scored jobs into the shortlist table."""

from __future__ import annotations

import argparse
from pathlib import Path

from jobfinder.core.storage import connect, init_db, query_jobs_for_shortlist, upsert_interesting_job


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Promote scored jobs into interesting_jobs.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument(
        "--decisions",
        nargs="+",
        default=["high"],
        help="AI decision values to promote, e.g. high maybe",
    )
    parser.add_argument("--status", default="active", help="Raw job lifecycle status filter")
    parser.add_argument("--limit", type=int, default=200, help="Max jobs to consider")
    return parser


def main() -> int:
    args = build_parser().parse_args()
    with connect(Path(args.db_path)) as conn:
        init_db(conn)
        rows = query_jobs_for_shortlist(
            conn,
            decisions=args.decisions,
            status=args.status,
            limit=args.limit,
        )
        for row in rows:
            upsert_interesting_job(conn, row)

    print(f"Promoted {len(rows)} job(s) into interesting_jobs")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
