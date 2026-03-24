"""CLI entrypoint for running a LinkedIn scrape."""

from __future__ import annotations

import argparse
from pathlib import Path

from jobfinder.core.normalize import normalize_raw_job
from jobfinder.core.storage import connect, create_run, finish_run, init_db, upsert_normalized_job, upsert_raw_job
from jobfinder.scrapers.linkedin import LinkedInScraper, LinkedInSearchConfig


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run a LinkedIn job scrape into SQLite.")
    parser.add_argument("--search-url", required=True, help="LinkedIn public jobs search URL")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--pages", type=int, default=1, help="Number of result pages to fetch")
    parser.add_argument(
        "--delay-seconds",
        type=float,
        default=0.5,
        help="Delay between job detail requests",
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    db_path = Path(args.db_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)

    config = LinkedInSearchConfig.from_search_url(args.search_url)
    scraper = LinkedInScraper(config=config, delay_seconds=args.delay_seconds)

    with connect(db_path) as conn:
        init_db(conn)
        run_id = create_run(conn, source=scraper.source, search_url=args.search_url)
        jobs_seen = 0
        try:
            jobs = scraper.scrape(max_pages=args.pages)
            for job in jobs:
                upsert_raw_job(conn, run_id=run_id, job=job)
                upsert_normalized_job(conn, normalize_raw_job(job))
            jobs_seen = len(jobs)
            finish_run(conn, run_id=run_id, source=scraper.source, jobs_seen=jobs_seen)
        except Exception as exc:
            finish_run(
                conn,
                run_id=run_id,
                source=scraper.source,
                jobs_seen=jobs_seen,
                error_message=str(exc),
            )
            raise

    print(f"Scraped {jobs_seen} LinkedIn jobs into {db_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
