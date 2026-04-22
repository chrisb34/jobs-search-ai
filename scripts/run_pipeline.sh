#!/bin/bash
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PYTHON_BIN="${PROJECT_ROOT}/.venv/bin/python"

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

run_step() {
  local label="$1"
  shift
  local exit_code=0

  log "START ${label}"
  "$@"
  exit_code=$?

  if [[ "${exit_code}" -eq 0 ]]; then
    log "OK    ${label}"
    return 0
  fi

  log "FAIL  ${label} (exit ${exit_code})"
  return "${exit_code}"
}

cd "${PROJECT_ROOT}" || exit 1

if [[ ! -x "${PYTHON_BIN}" ]]; then
  log "FAIL  Missing virtualenv python at ${PYTHON_BIN}"
  exit 1
fi

if [[ -f "${PROJECT_ROOT}/web/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "${PROJECT_ROOT}/web/.env"
  set +a
  log "Loaded environment from web/.env"
else
  log "WARN  web/.env not found; continuing without it"
fi

overall_exit=0

run_step "saved searches" "${PYTHON_BIN}" -m jobfinder.runs.run_saved_searches --config config/sources.yaml --pages 3 --auto-pages || overall_exit=1
run_step "rule scoring" "${PYTHON_BIN}" -m jobfinder.runs.score_jobs --criteria config/criteria.yaml || overall_exit=1
run_step "llm scoring" "${PYTHON_BIN}" -m jobfinder.runs.llm_score_jobs --criteria config/criteria.yaml --db-path data/jobs.db --min-rule-score 35 --limit 20 --only-unscored || overall_exit=1
run_step "shortlist promotion" "${PYTHON_BIN}" -m jobfinder.runs.promote_shortlist --decisions high maybe || overall_exit=1

if [[ "${overall_exit}" -eq 0 ]]; then
  log "PIPELINE COMPLETE"
else
  log "PIPELINE COMPLETE WITH FAILURES"
fi

exit "${overall_exit}"
