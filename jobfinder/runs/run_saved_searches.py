"""Run one or more saved searches from YAML config."""

from __future__ import annotations

import argparse
from pathlib import Path

from jobfinder.core.config import load_sources_config
from jobfinder.core.normalize import normalize_raw_job
from jobfinder.core.storage import connect, create_run, finish_run, init_db, upsert_normalized_job, upsert_raw_job
from jobfinder.scrapers.doctrine import DoctrineScraper, DoctrineSearchConfig
from jobfinder.scrapers.englishjobs import EnglishJobsScraper, EnglishJobsSearchConfig
from jobfinder.scrapers.linkedin import LinkedInScraper, LinkedInSearchConfig
from jobfinder.scrapers.remotefr import RemoteFrScraper, RemoteFrSearchConfig
from jobfinder.scrapers.wttj import WttjScraper, WttjSearchConfig


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run saved job searches from config.")
    parser.add_argument("--config", default="config/sources.yaml", help="YAML config path")
    parser.add_argument("--db-path", default="data/jobs.db", help="SQLite database path")
    parser.add_argument("--pages", type=int, default=1, help="Pages per saved search")
    parser.add_argument(
        "--auto-pages",
        action="store_true",
        help="Discover result pages from the LinkedIn search pager before scraping",
    )
    parser.add_argument("--delay-seconds", type=float, default=0.5, help="Delay between detail requests")
    parser.add_argument("--search-name", help="Optional single search name to run")
    return parser


def _run_single_search(
    conn,
    *,
    source: str,
    search_name: str,
    search_url: str,
    pages: int,
    auto_pages: bool,
    delay_seconds: float,
) -> int:
    if source == "linkedin":
        config = LinkedInSearchConfig.from_search_url(search_url)
        scraper = LinkedInScraper(config=config, delay_seconds=delay_seconds)
    elif source == "wttj":
        config = WttjSearchConfig.from_search_url(search_url)
        scraper = WttjScraper(config=config, delay_seconds=delay_seconds)
    elif source == "englishjobs":
        config = EnglishJobsSearchConfig.from_search_url(search_url)
        scraper = EnglishJobsScraper(config=config, delay_seconds=delay_seconds)
    elif source == "doctrine":
        config = DoctrineSearchConfig.from_search_url(search_url)
        scraper = DoctrineScraper(config=config, delay_seconds=delay_seconds)
    elif source == "remotefr":
        config = RemoteFrSearchConfig.from_search_url(search_url)
        scraper = RemoteFrScraper(config=config, delay_seconds=delay_seconds)
    else:
        raise SystemExit(f"Unsupported source: {source}")
    run_id = create_run(conn, source=scraper.source, search_url=search_url)
    jobs_seen = 0
    try:
        jobs = scraper.scrape(max_pages=pages, auto_pages=auto_pages)
        for job in jobs:
            upsert_raw_job(conn, run_id=run_id, job=job)
            upsert_normalized_job(conn, normalize_raw_job(job))
        jobs_seen = len(jobs)
        finish_run(conn, run_id=run_id, source=scraper.source, jobs_seen=jobs_seen)
        print(f"{search_name}: scraped {jobs_seen} jobs")
        return jobs_seen
    except Exception as exc:
        finish_run(
            conn,
            run_id=run_id,
            source=scraper.source,
            jobs_seen=jobs_seen,
            error_message=str(exc),
        )
        raise


def main() -> int:
    args = build_parser().parse_args()
    config = load_sources_config(args.config)
    searches = config["searches"]
    if args.search_name:
        searches = [search for search in searches if search.get("name") == args.search_name]
        if not searches:
            raise SystemExit(f"No search found with name: {args.search_name}")

    db_path = Path(args.db_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)

    total_jobs = 0
    failed_searches: list[tuple[str, str]] = []
    with connect(db_path) as conn:
        init_db(conn)
        for search in searches:
            search_name = search.get("name", "unnamed")
            try:
                total_jobs += _run_single_search(
                    conn,
                    source=search.get("source", ""),
                    search_name=search_name,
                    search_url=search["search_url"],
                    pages=args.pages,
                    auto_pages=args.auto_pages,
                    delay_seconds=args.delay_seconds,
                )
            except Exception as exc:
                failed_searches.append((search_name, str(exc)))
                print(f"{search_name}: failed - {exc}")

    print(f"Completed {len(searches)} search(es), scraped {total_jobs} jobs total")
    if failed_searches:
        print(f"Failed searches: {len(failed_searches)}")
        for search_name, error in failed_searches:
            print(f"- {search_name}: {error}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
