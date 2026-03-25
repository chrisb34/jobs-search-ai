"""Welcome to the Jungle jobs scraper."""

from __future__ import annotations

import hashlib
import html
import json
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import parse_qs, urlencode, urlparse

import requests
from bs4 import BeautifulSoup


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)

ALGOLIA_APP_ID = "CSEKHVMS53"
ALGOLIA_API_KEY = "4bd8f6215d0cc52b26430765769e65a0"
ALGOLIA_INDEX_BY_LOCALE = {
    "fr": "wttj_jobs_production_fr",
    "en": "wttj_jobs_production_en",
}
ALGOLIA_ENDPOINT = f"https://{ALGOLIA_APP_ID.lower()}-dsn.algolia.net/1/indexes/*/queries"
WTTJ_BASE_URL = "https://www.welcometothejungle.com"
REMOTE_LABELS = {
    "fulltime": "remote",
    "partial": "hybrid",
    "punctual": "remote",
    "no": "onsite",
}
CONTRACT_LABELS = {
    "full_time": "full_time",
    "part_time": "part_time",
    "internship": "internship",
    "freelance": "freelance",
    "temporary": "temporary",
    "apprenticeship": "apprenticeship",
    "graduate_program": "graduate_program",
    "other": "other",
    "vie": "vie",
    "idv": "idv",
    "volunteer": "volunteer",
}


def _first_value(params: dict[str, list[str]], key: str) -> str | None:
    values = params.get(key)
    if not values:
        return None
    return values[0]


@dataclass
class WttjSearchConfig:
    search_url: str
    language: str
    query: str | None
    page: int
    params: dict[str, list[str]]

    @classmethod
    def from_search_url(cls, search_url: str) -> "WttjSearchConfig":
        parsed = urlparse(search_url)
        parts = [part for part in parsed.path.split("/") if part]
        language = parts[0] if parts else "fr"
        params = parse_qs(parsed.query)
        page_value = _first_value(params, "page")
        page = max(int(page_value or "1"), 1)
        return cls(
            search_url=search_url,
            language=language,
            query=_first_value(params, "query"),
            page=page,
            params=params,
        )

    @property
    def index_name(self) -> str:
        return ALGOLIA_INDEX_BY_LOCALE.get(self.language, ALGOLIA_INDEX_BY_LOCALE["fr"])

    def search_params(self, *, page: int, hits_per_page: int) -> str:
        payload: dict[str, Any] = {
            "query": self.query or "",
            "page": max(page - 1, 0),
            "hitsPerPage": hits_per_page,
            "clickAnalytics": "true",
        }
        facet_filters = self._facet_filters()
        if facet_filters:
            payload["facetFilters"] = json.dumps(facet_filters, ensure_ascii=True)
        return urlencode(payload)

    def _facet_filters(self) -> list[str]:
        filters: list[str] = []
        for key, values in self.params.items():
            if key == "refinementList[offices.country_code][]":
                filters.extend(f"offices.country_code:{value}" for value in values if value)
            elif key == "refinementList[contract_type][]":
                filters.extend(f"contract_type:{value}" for value in values if value)
            elif key == "refinementList[remote][]":
                filters.extend(f"remote:{value}" for value in values if value)
            elif key == "collections[]":
                for value in values:
                    if value == "remote_friendly":
                        filters.append("has_remote:true")
        return filters

    def preferred_country_codes(self) -> set[str]:
        return set(self.params.get("refinementList[offices.country_code][]", []))


class WttjScraper:
    source = "wttj"

    def __init__(
        self,
        config: WttjSearchConfig,
        delay_seconds: float = 0.5,
        hits_per_page: int = 20,
    ) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.hits_per_page = hits_per_page
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": f"{config.language}-FR,{config.language};q=0.9,en;q=0.8",
                "Origin": WTTJ_BASE_URL,
                "Referer": config.search_url,
                "X-Algolia-API-Key": ALGOLIA_API_KEY,
                "X-Algolia-Application-Id": ALGOLIA_APP_ID,
            }
        )

    def scrape(self, max_pages: int = 1, auto_pages: bool = False) -> list[dict]:
        jobs: list[dict] = []
        seen_ids: set[str] = set()

        initial_page, total_pages = self._search_page(self.config.page)
        pages_to_fetch = self._build_page_plan(max_pages=max_pages, auto_pages=auto_pages, total_pages=total_pages)

        for page in pages_to_fetch:
            result = initial_page if page == self.config.page else self._search_page(page)[0]
            hits = result.get("hits", [])
            if not hits:
                break

            new_hits = 0
            for hit in hits:
                source_job_id = hit.get("reference")
                if not source_job_id or source_job_id in seen_ids:
                    continue
                detail = self._fetch_job_detail(self._job_url(hit))
                jobs.append(self._build_raw_job(hit, detail))
                seen_ids.add(source_job_id)
                new_hits += 1
                if self.delay_seconds:
                    time.sleep(self.delay_seconds)

            if new_hits == 0:
                break

        return jobs

    def _build_page_plan(self, *, max_pages: int, auto_pages: bool, total_pages: int) -> list[int]:
        if auto_pages:
            end_page = min(self.config.page + max_pages - 1, total_pages)
        else:
            end_page = self.config.page + max_pages - 1
        return list(range(self.config.page, max(self.config.page, end_page) + 1))

    def _search_page(self, page: int) -> tuple[dict[str, Any], int]:
        payload = {
            "requests": [
                {
                    "indexName": self.config.index_name,
                    "params": self.config.search_params(page=page, hits_per_page=self.hits_per_page),
                }
            ]
        }
        response = self.session.post(ALGOLIA_ENDPOINT, json=payload, timeout=30)
        response.raise_for_status()
        result = response.json()["results"][0]
        total_pages = int(result.get("nbPages", page))
        return result, total_pages

    def _job_url(self, hit: dict[str, Any]) -> str:
        organization_slug = (hit.get("organization") or {}).get("slug")
        job_slug = hit.get("slug")
        if not organization_slug or not job_slug:
            raise ValueError("Missing organization slug or job slug in WTTJ hit")
        return f"{WTTJ_BASE_URL}/{self.config.language}/companies/{organization_slug}/jobs/{job_slug}"

    def _fetch_job_detail(self, job_url: str) -> dict[str, Any]:
        response = self.session.get(job_url, timeout=30)
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "html.parser")
        job_posting = self._extract_job_posting(soup)
        return {
            "description_raw": self._extract_description(job_posting),
            "job_posting": job_posting,
        }

    def _extract_job_posting(self, soup: BeautifulSoup) -> dict[str, Any]:
        for node in soup.select('script[type="application/ld+json"]'):
            raw_text = node.string or node.get_text(strip=True)
            if not raw_text:
                continue
            try:
                parsed = json.loads(raw_text)
            except json.JSONDecodeError:
                continue
            if parsed.get("@type") == "JobPosting":
                return parsed
        return {}

    def _extract_description(self, job_posting: dict[str, Any]) -> str | None:
        description = job_posting.get("description")
        if not description:
            return None
        return BeautifulSoup(html.unescape(description), "html.parser").get_text("\n", strip=True)

    def _build_raw_job(self, hit: dict[str, Any], detail: dict[str, Any]) -> dict[str, Any]:
        office = self._select_office(hit.get("offices") or [])
        location_raw = ", ".join(
            value for value in [office.get("city"), office.get("country")] if value
        ) or None
        remote_raw = REMOTE_LABELS.get(hit.get("remote"), hit.get("remote"))
        contract_raw = CONTRACT_LABELS.get(hit.get("contract_type"), hit.get("contract_type"))
        salary = self._salary_payload(hit)
        description_raw = detail.get("description_raw") or hit.get("summary")
        extra = {
            "country": office.get("country_code"),
            "city": office.get("city"),
            "job_location": {
                "addressCountry": office.get("country_code"),
                "addressLocality": office.get("city"),
                "addressRegion": office.get("state"),
            },
            "job_location_type": self._job_location_type(hit.get("remote")),
            "salary": salary,
            "language": hit.get("language"),
            "summary": hit.get("summary"),
            "key_missions": hit.get("key_missions"),
            "profession": hit.get("new_profession"),
            "organization": hit.get("organization"),
            "hit": hit,
            "job_posting": detail.get("job_posting"),
        }
        return {
            "source": self.source,
            "source_job_id": hit["reference"],
            "url": self._job_url(hit),
            "title": hit.get("name"),
            "company": (hit.get("organization") or {}).get("name"),
            "location_raw": location_raw,
            "description_raw": description_raw,
            "salary_raw": self._salary_text(hit),
            "contract_raw": contract_raw,
            "remote_raw": remote_raw,
            "posted_at_raw": hit.get("published_at_date"),
            "listed_at": (detail.get("job_posting") or {}).get("datePosted") or hit.get("published_at_date"),
            "content_hash": self._content_hash(hit=hit, description_raw=description_raw),
            "extra": extra,
        }

    def _select_office(self, offices: list[dict[str, Any]]) -> dict[str, Any]:
        if not offices:
            return {}
        preferred_country_codes = self.config.preferred_country_codes()
        if preferred_country_codes:
            for office in offices:
                if office.get("country_code") in preferred_country_codes:
                    return office
        return offices[0]

    def _job_location_type(self, remote_code: str | None) -> str | None:
        if remote_code == "fulltime":
            return "TELECOMMUTE"
        if remote_code == "partial":
            return "HYBRID"
        return None

    def _salary_payload(self, hit: dict[str, Any]) -> dict[str, Any] | None:
        minimum = hit.get("salary_minimum")
        maximum = hit.get("salary_maximum")
        currency = hit.get("salary_currency")
        if minimum is None and maximum is None and currency is None:
            return None
        return {
            "min": minimum,
            "max": maximum,
            "currency": currency,
            "period": hit.get("salary_period"),
        }

    def _salary_text(self, hit: dict[str, Any]) -> str | None:
        minimum = hit.get("salary_minimum")
        maximum = hit.get("salary_maximum")
        currency = hit.get("salary_currency")
        period = hit.get("salary_period")
        if minimum is None and maximum is None and currency is None:
            return None
        return json.dumps(
            {
                "min": minimum,
                "max": maximum,
                "currency": currency,
                "period": period,
            },
            ensure_ascii=True,
            sort_keys=True,
        )

    def _content_hash(self, *, hit: dict[str, Any], description_raw: str | None) -> str:
        payload = {
            "reference": hit.get("reference"),
            "published_at": hit.get("published_at"),
            "salary_minimum": hit.get("salary_minimum"),
            "salary_maximum": hit.get("salary_maximum"),
            "remote": hit.get("remote"),
            "description_raw": description_raw,
        }
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, sort_keys=True).encode("utf-8")).hexdigest()
