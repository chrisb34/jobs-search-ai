"""Review/export active jobs from SQLite."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path

from jobfinder.core.storage import connect, query_review_jobs


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Review or export jobs from SQLite.")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--status", default="active", help="Job lifecycle status filter")
    parser.add_argument("--limit", type=int, default=50, help="Max rows to return")
    parser.add_argument("--remote-only", action="store_true", help="Only return remote jobs")
    parser.add_argument(
        "--format",
        choices=("table", "json", "csv"),
        default="table",
        help="Output format",
    )
    parser.add_argument("--output", help="Optional output file for csv/json")
    return parser


def _rows_to_dicts(rows: list) -> list[dict]:
    return [dict(row) for row in rows]


def _print_table(rows: list[dict]) -> None:
    if not rows:
        print("No jobs found.")
        return

    headers = ["source", "title", "company", "location_raw", "remote_type", "posted_at_raw"]
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
    db_path = Path(args.db_path)
    with connect(db_path) as conn:
        rows = query_review_jobs(
            conn,
            status=args.status,
            limit=args.limit,
            remote_only=args.remote_only,
        )

    dict_rows = _rows_to_dicts(rows)

    if args.format == "table":
        _print_table(dict_rows)
        return 0

    if args.format == "json":
        payload = json.dumps(dict_rows, ensure_ascii=True, indent=2)
        if args.output:
            Path(args.output).write_text(payload + "\n", encoding="utf-8")
        else:
            print(payload)
        return 0

    if not args.output:
        raise SystemExit("--output is required for csv format")

    with open(args.output, "w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(dict_rows[0].keys()) if dict_rows else [])
        if dict_rows:
            writer.writeheader()
            writer.writerows(dict_rows)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
