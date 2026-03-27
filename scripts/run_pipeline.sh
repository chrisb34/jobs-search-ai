#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}" || exit 1
"${PROJECT_ROOT}/.venv/bin/python" -m jobfinder.runs.run_saved_searches --config config/sources.yaml --pages 3 --auto-pages
"${PROJECT_ROOT}/.venv/bin/python" -m jobfinder.runs.score_jobs --criteria config/criteria.yaml
"${PROJECT_ROOT}/.venv/bin/python" -m jobfinder.runs.llm_score_jobs --criteria config/criteria.yaml --db-path data/jobs.db --min-rule-score 35 --limit 20 --only-unscored
"${PROJECT_ROOT}/.venv/bin/python" -m jobfinder.runs.promote_shortlist --decisions high maybe
