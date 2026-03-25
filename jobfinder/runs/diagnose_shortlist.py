"""Diagnose shortlist gaps, duplicates, and stale rows."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from jobfinder.core.storage import (
    connect,
    query_collapsed_duplicate_jobs,
    query_shortlist_gap_jobs,
    query_stale_shortlist_jobs,
)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Diagnose shortlist coverage and dedupe behavior.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument(
        "--decisions",
        nargs="+",
        default=["high", "maybe"],
        help="AI decisions to include when checking shortlist gaps and dedupe",
    )
    parser.add_argument("--status", default="active", help="Raw job lifecycle status filter")
    parser.add_argument("--limit", type=int, default=50, help="Max rows per section")
    parser.add_argument("--format", choices=("table", "json"), default="table", help="Output format")
    return parser


def _rows_to_dicts(rows: list) -> list[dict]:
    return [dict(row) for row in rows]


def _print_section(title: str, rows: list[dict], headers: list[str]) -> None:
    print(title)
    if not rows:
        print("  none")
        return
    widths = {
        header: max(len(header), *(len(str(row.get(header) or "")) for row in rows))
        for header in headers
    }
    print("  " + "  ".join(header.ljust(widths[header]) for header in headers))
    print("  " + "  ".join("-" * widths[header] for header in headers))
    for row in rows:
        print("  " + "  ".join(str(row.get(header) or "").ljust(widths[header]) for header in headers))


def main() -> int:
    args = build_parser().parse_args()

    with connect(Path(args.db_path)) as conn:
        missing = _rows_to_dicts(
            query_shortlist_gap_jobs(
                conn,
                decisions=args.decisions,
                status=args.status,
                limit=args.limit,
            )
        )
        duplicates = _rows_to_dicts(
            query_collapsed_duplicate_jobs(
                conn,
                decisions=args.decisions,
                status=args.status,
                limit=args.limit,
            )
        )
        stale = _rows_to_dicts(query_stale_shortlist_jobs(conn, limit=args.limit))

    if args.format == "json":
        print(
            json.dumps(
                {
                    "missing_shortlist": missing,
                    "collapsed_duplicates": duplicates,
                    "stale_shortlist": stale,
                },
                ensure_ascii=True,
                indent=2,
            )
        )
        return 0

    _print_section(
        "Missing From Shortlist",
        missing,
        ["source", "source_job_id", "title", "company", "ai_score", "ai_decision"],
    )
    print()
    _print_section(
        "Collapsed Duplicates",
        duplicates,
        ["canonical_job_key", "duplicate_count", "top_score", "members"],
    )
    print()
    _print_section(
        "Stale Shortlist Rows",
        stale,
        [
            "id",
            "source",
            "source_job_id",
            "title",
            "shortlist_ai_score",
            "normalized_ai_score",
            "shortlist_ai_decision",
            "normalized_ai_decision",
        ],
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
