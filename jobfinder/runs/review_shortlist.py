"""Review shortlisted jobs."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from jobfinder.core.storage import connect, query_interesting_jobs


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Review interesting_jobs.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--status", help="Shortlist status filter, e.g. new or applied")
    parser.add_argument("--decision", help="AI decision filter, e.g. high or maybe")
    parser.add_argument("--limit", type=int, default=50, help="Max rows to return")
    parser.add_argument("--format", choices=("table", "json"), default="table", help="Output format")
    return parser


def _rows_to_dicts(rows: list) -> list[dict]:
    return [dict(row) for row in rows]


def _print_table(rows: list[dict]) -> None:
    if not rows:
        print("No shortlisted jobs found.")
        return
    headers = ["title", "company", "location_raw", "ai_score", "ai_decision", "shortlist_status"]
    widths = {
        header: max(len(header), *(len(str(row.get(header) or "")) for row in rows))
        for header in headers
    }
    print("  ".join(header.ljust(widths[header]) for header in headers))
    print("  ".join("-" * widths[header] for header in headers))
    for row in rows:
        print("  ".join(str(row.get(header) or "").ljust(widths[header]) for header in headers))


def main() -> int:
    args = build_parser().parse_args()
    with connect(Path(args.db_path)) as conn:
        rows = query_interesting_jobs(
            conn,
            shortlist_status=args.status,
            decision=args.decision,
            limit=args.limit,
        )
    dict_rows = _rows_to_dicts(rows)
    if args.format == "json":
        print(json.dumps(dict_rows, ensure_ascii=True, indent=2))
    else:
        _print_table(dict_rows)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
