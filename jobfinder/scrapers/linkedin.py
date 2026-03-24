"""LinkedIn public jobs scraper."""

from __future__ import annotations

import hashlib
import html
import json
import re
import time
from dataclasses import dataclass
from typing import Iterator
from urllib.parse import parse_qs, urlencode, urlparse

import requests
from bs4 import BeautifulSoup
from requests import HTTPError


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)


@dataclass
class LinkedInSearchConfig:
    search_url: str
    keywords: str | None
    geo_id: str | None
    work_type: str | None
    start: int = 0

    @classmethod
    def from_search_url(cls, search_url: str) -> "LinkedInSearchConfig":
        parsed = urlparse(search_url)
        params = parse_qs(parsed.query)
        return cls(
            search_url=search_url,
            keywords=params.get("keywords", [None])[0],
            geo_id=params.get("geoId", [None])[0],
            work_type=params.get("f_WT", [None])[0],
            start=int(params.get("start", ["0"])[0] or 0),
        )

    def search_api_url(self, start: int | None = None) -> str:
        current_start = self.start if start is None else start
        query = {
            "keywords": self.keywords,
            "geoId": self.geo_id,
            "f_WT": self.work_type,
            "start": str(current_start),
        }
        filtered = {key: value for key, value in query.items() if value}
        return (
            "https://www.linkedin.com/jobs-guest/jobs/api/seeMoreJobPostings/search?"
            + urlencode(filtered)
        )


class LinkedInScraper:
    source = "linkedin"

    def __init__(
        self,
        config: LinkedInSearchConfig,
        delay_seconds: float = 0.5,
        max_retries: int = 3,
    ) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.max_retries = max_retries
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": USER_AGENT, "Accept-Language": "en-US,en;q=0.9"})

    def scrape(self, max_pages: int = 1) -> list[dict]:
        jobs: list[dict] = []
        seen_ids: set[str] = set()
        for page in range(max_pages):
            start = self.config.start + (page * 25)
            html_page = self._fetch(self.config.search_api_url(start=start))
            cards = list(self._parse_search_cards(html_page))
            if not cards:
                break
            for card in cards:
                if card["source_job_id"] in seen_ids:
                    continue
                detail = self._fetch_job_detail(card["url"])
                jobs.append(self._build_raw_job(card, detail))
                seen_ids.add(card["source_job_id"])
                if self.delay_seconds:
                    time.sleep(self.delay_seconds)
        return jobs

    def _fetch(self, url: str) -> str:
        last_error: Exception | None = None
        for attempt in range(self.max_retries + 1):
            response = self.session.get(url, timeout=30)
            try:
                response.raise_for_status()
                return response.text
            except HTTPError as exc:
                last_error = exc
                status_code = exc.response.status_code if exc.response is not None else None
                if status_code != 429 or attempt == self.max_retries:
                    raise
                time.sleep((attempt + 1) * max(self.delay_seconds, 1.0))
        if last_error:
            raise last_error
        raise RuntimeError(f"Failed to fetch URL: {url}")

    def _parse_search_cards(self, page_html: str) -> Iterator[dict]:
        soup = BeautifulSoup(page_html, "html.parser")
        for card in soup.select("div.base-search-card"):
            entity_urn = card.get("data-entity-urn", "")
            source_job_id = entity_urn.rsplit(":", 1)[-1] if entity_urn else None
            link = card.select_one("a.base-card__full-link")
            title = card.select_one("h3.base-search-card__title")
            company = card.select_one("h4.base-search-card__subtitle")
            location = card.select_one("span.job-search-card__location")
            listed = card.select_one("time")
            if not source_job_id or not link:
                continue
            yield {
                "source_job_id": source_job_id,
                "url": link.get("href"),
                "title": title.get_text(" ", strip=True) if title else None,
                "company": company.get_text(" ", strip=True) if company else None,
                "location_raw": location.get_text(" ", strip=True) if location else None,
                "listed_at": listed.get("datetime") if listed else None,
                "posted_at_raw": listed.get_text(" ", strip=True) if listed else None,
            }

    def _fetch_job_detail(self, job_url: str) -> dict:
        try:
            page_html = self._fetch(job_url)
        except HTTPError as exc:
            if exc.response is not None and exc.response.status_code == 429:
                return {
                    "description_raw": None,
                    "salary_raw": None,
                    "contract_raw": None,
                    "remote_raw": None,
                    "extra": {
                        "detail_fetch_error": "rate_limited",
                    },
                }
            raise
        soup = BeautifulSoup(page_html, "html.parser")
        job_posting = self._extract_job_posting(soup)
        criteria = self._extract_criteria(soup)
        return {
            "description_raw": self._extract_description(soup, job_posting),
            "salary_raw": self._extract_salary_text(soup, job_posting),
            "contract_raw": criteria.get("employment type") or job_posting.get("employmentType"),
            "remote_raw": (
                criteria.get("workplace type")
                or criteria.get("mode de travail")
                or job_posting.get("jobLocationType")
            ),
            "extra": {
                "job_posting": job_posting,
                "criteria": criteria,
                "country": ((job_posting.get("jobLocation") or {}).get("address") or {}).get("addressCountry"),
                "city": ((job_posting.get("jobLocation") or {}).get("address") or {}).get("addressLocality"),
                "job_location": (job_posting.get("jobLocation") or {}).get("address"),
                "job_location_type": job_posting.get("jobLocationType"),
                "salary": self._extract_salary_struct(job_posting),
                "seniority_level": criteria.get("seniority level"),
                "skills": job_posting.get("skills"),
            },
        }

    def _extract_job_posting(self, soup: BeautifulSoup) -> dict:
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

    def _extract_description(self, soup: BeautifulSoup, job_posting: dict) -> str | None:
        content = soup.select_one("div.show-more-less-html__markup")
        if content:
            return content.get_text("\n", strip=True)
        description = job_posting.get("description")
        if description:
            return BeautifulSoup(html.unescape(description), "html.parser").get_text("\n", strip=True)
        return None

    def _extract_criteria(self, soup: BeautifulSoup) -> dict[str, str]:
        criteria: dict[str, str] = {}
        items = soup.select("li.description__job-criteria-item")
        for item in items:
            key = item.select_one("h3.description__job-criteria-subheader")
            value = item.select_one("span.description__job-criteria-text")
            if key and value:
                criteria[key.get_text(" ", strip=True).lower()] = value.get_text(" ", strip=True)
        return criteria

    def _extract_salary_text(self, soup: BeautifulSoup, job_posting: dict) -> str | None:
        node = soup.select_one("div.compensation__description")
        if node:
            return node.get_text(" ", strip=True)
        salary = self._extract_salary_struct(job_posting)
        if salary:
            return json.dumps(salary, ensure_ascii=True, sort_keys=True)
        return None

    def _extract_salary_struct(self, job_posting: dict) -> dict | None:
        base_salary = job_posting.get("baseSalary") or {}
        value = base_salary.get("value") or {}
        if not value:
            return None
        return {
            "currency": base_salary.get("currency"),
            "min": value.get("minValue"),
            "max": value.get("maxValue"),
            "unit": value.get("unitText"),
        }

    def _build_raw_job(self, card: dict, detail: dict) -> dict:
        merged = {
            "source": self.source,
            **card,
            **detail,
        }
        merged["content_hash"] = self._content_hash(merged)
        return merged

    def _content_hash(self, job: dict) -> str:
        basis = "||".join(
            [
                job.get("title") or "",
                job.get("company") or "",
                job.get("location_raw") or "",
                re.sub(r"\s+", " ", job.get("description_raw") or "").strip(),
            ]
        )
        return hashlib.sha256(basis.encode("utf-8")).hexdigest()
