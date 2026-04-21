"""CLI entrypoint for running a supported job scrape."""

from __future__ import annotations

import argparse
from pathlib import Path
from urllib.parse import urlparse

from jobfinder.core.normalize import normalize_raw_job
from jobfinder.scrapers.englishjobs import EnglishJobsScraper, EnglishJobsSearchConfig
from jobfinder.core.storage import connect, create_run, finish_run, init_db, upsert_normalized_job, upsert_raw_job
from jobfinder.scrapers.doctrine import DoctrineScraper, DoctrineSearchConfig
from jobfinder.scrapers.linkedin import LinkedInScraper, LinkedInSearchConfig
from jobfinder.scrapers.remotefr import RemoteFrScraper, RemoteFrSearchConfig
from jobfinder.scrapers.wttj import WttjScraper, WttjSearchConfig


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run a job scrape into SQLite.")
    parser.add_argument("--search-url", required=True, help="Supported job search URL")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--pages", type=int, default=1, help="Number of result pages to fetch")
    parser.add_argument(
        "--auto-pages",
        action="store_true",
        help="Discover or use source-specific pagination metadata before scraping",
    )
    parser.add_argument(
        "--delay-seconds",
        type=float,
        default=0.5,
        help="Delay between job detail requests",
    )
    return parser


def _build_scraper(search_url: str, delay_seconds: float):
    host = urlparse(search_url).netloc.lower()
    if "linkedin.com" in host:
        config = LinkedInSearchConfig.from_search_url(search_url)
        return LinkedInScraper(config=config, delay_seconds=delay_seconds)
    if "welcometothejungle.com" in host:
        config = WttjSearchConfig.from_search_url(search_url)
        return WttjScraper(config=config, delay_seconds=delay_seconds)
    if "englishjobs.fr" in host:
        config = EnglishJobsSearchConfig.from_search_url(search_url)
        return EnglishJobsScraper(config=config, delay_seconds=delay_seconds)
    if "doctrine.fr" in host or "jobs.lever.co" in host:
        config = DoctrineSearchConfig.from_search_url(search_url)
        return DoctrineScraper(config=config, delay_seconds=delay_seconds)
    if "remotefr.com" in host:
        config = RemoteFrSearchConfig.from_search_url(search_url)
        return RemoteFrScraper(config=config, delay_seconds=delay_seconds)
    raise SystemExit(f"Unsupported search URL host: {host}")


def main() -> int:
    args = build_parser().parse_args()
    db_path = Path(args.db_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)

    scraper = _build_scraper(args.search_url, args.delay_seconds)

    with connect(db_path) as conn:
        init_db(conn)
        run_id = create_run(conn, source=scraper.source, search_url=args.search_url)
        jobs_seen = 0
        try:
            jobs = scraper.scrape(max_pages=args.pages, auto_pages=args.auto_pages)
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

    print(f"Scraped {jobs_seen} {scraper.source} jobs into {db_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
