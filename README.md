# jobfinder

Initial MVP for a job search scraper and SQLite pipeline.

## Current scope

- LinkedIn public jobs search scraper
- Raw job storage in SQLite
- Central normalization step into a second table
- Run tracking and simple lifecycle status updates
- Saved search config via YAML
- Review/export command for active jobs

## Quick start

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -e .
python -m jobfinder.runs.run_jobs \
  --search-url 'https://www.linkedin.com/jobs/search/?currentJobId=4386382859&f_WT=2&geoId=105015875&keywords=tech%20lead&origin=JOB_SEARCH_PAGE_JOB_FILTER&refresh=true' \
  --pages 3 \
  --auto-pages
```

The database will be created at `data/jobs.db`.

Run saved searches from config:

```bash
python -m jobfinder.runs.run_saved_searches --config config/sources.yaml --pages 3 --auto-pages
```

Review/export jobs:

```bash
python -m jobfinder.runs.export_jobs --limit 20 --format table
python -m jobfinder.runs.export_jobs --remote-only --format json --output data/remote_jobs.json
```

## Schema overview

`raw_jobs`

- Stores source-specific scraped data
- Keeps `first_seen_at`, `last_seen_at`, `status`, `missing_runs`, and `content_hash`

`normalized_jobs`

- Stores the centralized cleaned shape for later scoring and dedupe

`scrape_runs`

- Stores one record per run for traceability and lifecycle updates

## Notes

- This scraper uses LinkedIn's public guest job pages and is intentionally conservative.
- LinkedIn markup can change. Expect the scraper to need maintenance.
- The current normalization is basic and intended as a Phase 1 foundation.
- If a LinkedIn search URL includes `start=25`, `start=50`, and so on, the scraper now uses that as the base pagination offset.
- With `--auto-pages`, the scraper also loads the full LinkedIn search page, reads visible pager links when present, and computes page offsets in `25`-job steps.
