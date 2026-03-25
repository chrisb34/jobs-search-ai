"""EnglishJobs.fr scraper."""

from __future__ import annotations

import hashlib
import html
import json
import re
import time
from dataclasses import dataclass
from typing import Any, Iterator
from urllib.parse import parse_qs, urlencode, urljoin, urlparse

import requests
from bs4 import BeautifulSoup


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)
ENGLISHJOBS_BASE_URL = "https://englishjobs.fr"


@dataclass
class EnglishJobsSearchConfig:
    search_url: str
    path: str
    page: int = 1

    @classmethod
    def from_search_url(cls, search_url: str) -> "EnglishJobsSearchConfig":
        parsed = urlparse(search_url)
        params = parse_qs(parsed.query)
        page = max(int(params.get("page", ["1"])[0] or "1"), 1)
        return cls(search_url=search_url, path=parsed.path or "/jobs/remote", page=page)

    def page_url(self, page: int) -> str:
        if page <= 1:
            return urljoin(ENGLISHJOBS_BASE_URL, self.path)
        return urljoin(ENGLISHJOBS_BASE_URL, f"{self.path}?{urlencode({'page': page})}")


class EnglishJobsScraper:
    source = "englishjobs"

    def __init__(self, config: EnglishJobsSearchConfig, delay_seconds: float = 0.5) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": "en-US,en;q=0.9,fr;q=0.8",
            }
        )

    def scrape(self, max_pages: int = 1, auto_pages: bool = False) -> list[dict]:
        jobs: list[dict] = []
        seen_ids: set[str] = set()

        first_page_html = self._fetch(self.config.page_url(self.config.page))
        page_numbers = self._discover_pages(first_page_html) if auto_pages else []
        pages = self._build_page_plan(max_pages=max_pages, discovered_pages=page_numbers)

        prefetched = {self.config.page: first_page_html}
        for page in pages:
            page_html = prefetched.get(page) or self._fetch(self.config.page_url(page))
            cards = list(self._parse_listing_cards(page_html))
            if not cards:
                break
            new_cards = 0
            for card in cards:
                if card["source_job_id"] in seen_ids:
                    continue
                detail = self._fetch_job_detail(card)
                jobs.append(self._build_raw_job(card, detail))
                seen_ids.add(card["source_job_id"])
                new_cards += 1
                if self.delay_seconds:
                    time.sleep(self.delay_seconds)
            if new_cards == 0:
                break

        return jobs

    def _build_page_plan(self, *, max_pages: int, discovered_pages: list[int]) -> list[int]:
        if discovered_pages:
            pages = [page for page in discovered_pages if page >= self.config.page]
            return pages[:max_pages]
        return list(range(self.config.page, self.config.page + max_pages))

    def _discover_pages(self, page_html: str) -> list[int]:
        soup = BeautifulSoup(page_html, "html.parser")
        pages: set[int] = set()
        for a in soup.select("a[href*='/jobs/remote?page=']"):
            href = a.get("href") or ""
            match = re.search(r"[?&]page=(\d+)", href)
            if match:
                pages.add(int(match.group(1)))
        pages.add(self.config.page)
        return sorted(pages)

    def _fetch(self, url: str) -> str:
        response = self.session.get(url, timeout=30)
        response.raise_for_status()
        return response.text

    def _parse_listing_cards(self, page_html: str) -> Iterator[dict]:
        soup = BeautifulSoup(page_html, "html.parser")
        for item in soup.select("div.job.js-job"):
            source_job_id = item.get("id")
            main_link = item.select_one("a.js-joblink[href]")
            if not source_job_id or main_link is None:
                continue
            href = main_link.get("href") or ""
            title = main_link.get_text(" ", strip=True)
            meta_items = [li.get_text(" ", strip=True) for li in item.select("ul.space-y-1 li")]
            company = meta_items[0] if len(meta_items) > 0 else None
            location_raw = meta_items[1] if len(meta_items) > 1 else None
            posted_at_raw = meta_items[2] if len(meta_items) > 2 else None
            summary_node = item.select_one("div.mr-4.flex-1.pt-0.text-sm.leading-5.text-gray-400")
            summary = summary_node.get_text(" ", strip=True) if summary_node else None
            yield {
                "source_job_id": source_job_id,
                "url": urljoin(ENGLISHJOBS_BASE_URL, href),
                "title": title,
                "company": company,
                "location_raw": location_raw,
                "posted_at_raw": posted_at_raw,
                "listed_at": posted_at_raw,
                "summary": summary,
                "is_internal": href.startswith("/job/"),
                "listing_meta": meta_items,
            }

    def _fetch_job_detail(self, card: dict[str, Any]) -> dict[str, Any]:
        if not card["is_internal"]:
            return {
                "description_raw": card.get("summary"),
                "job_posting": {},
            }

        page_html = self._fetch(card["url"])
        soup = BeautifulSoup(page_html, "html.parser")
        job_posting = self._extract_job_posting(soup)
        return {
            "description_raw": self._extract_description(soup, job_posting),
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

    def _extract_description(self, soup: BeautifulSoup, job_posting: dict[str, Any]) -> str | None:
        section = soup.select_one("section")
        if section:
            return section.get_text("\n", strip=True)
        description = job_posting.get("description")
        if description:
            return BeautifulSoup(html.unescape(description), "html.parser").get_text("\n", strip=True)
        return None

    def _build_raw_job(self, card: dict[str, Any], detail: dict[str, Any]) -> dict[str, Any]:
        job_posting = detail.get("job_posting") or {}
        salary = self._extract_salary_struct(job_posting)
        country, city = self._split_location(card.get("location_raw"))
        extra = {
            "country": country,
            "city": city,
            "job_location": {
                "addressCountry": country,
                "addressLocality": city,
            },
            "job_location_type": "TELECOMMUTE" if "remote" in (card.get("location_raw") or "").lower() else None,
            "salary": salary,
            "language": "en",
            "job_posting": job_posting,
            "listing_meta": card.get("listing_meta"),
            "is_internal": card.get("is_internal"),
        }
        return {
            "source": self.source,
            "source_job_id": card["source_job_id"],
            "url": card["url"],
            "title": card.get("title"),
            "company": card.get("company"),
            "location_raw": card.get("location_raw"),
            "description_raw": detail.get("description_raw"),
            "salary_raw": json.dumps(salary, ensure_ascii=True, sort_keys=True) if salary else None,
            "contract_raw": job_posting.get("employmentType"),
            "remote_raw": "remote" if "remote" in (card.get("location_raw") or "").lower() else None,
            "posted_at_raw": card.get("posted_at_raw"),
            "listed_at": job_posting.get("datePosted") or card.get("listed_at"),
            "content_hash": self._content_hash(card, detail.get("description_raw")),
            "extra": extra,
        }

    def _extract_salary_struct(self, job_posting: dict[str, Any]) -> dict[str, Any] | None:
        base_salary = job_posting.get("baseSalary") or {}
        value = base_salary.get("value") or {}
        currency = base_salary.get("currency")
        minimum = value.get("minValue")
        maximum = value.get("maxValue")
        if currency is None and minimum is None and maximum is None:
            return None
        return {
            "currency": currency,
            "min": minimum,
            "max": maximum,
            "period": value.get("unitText"),
        }

    def _split_location(self, location_raw: str | None) -> tuple[str | None, str | None]:
        if not location_raw:
            return None, None
        text = location_raw.strip()
        if text.lower() == "remote":
            return None, None
        parts = [part.strip() for part in text.split(",") if part.strip()]
        if len(parts) == 1:
            return parts[0].upper() if len(parts[0]) == 2 else parts[0], None
        return parts[-1], parts[0]

    def _content_hash(self, card: dict[str, Any], description_raw: str | None) -> str:
        payload = {
            "source_job_id": card["source_job_id"],
            "title": card.get("title"),
            "company": card.get("company"),
            "posted_at_raw": card.get("posted_at_raw"),
            "description_raw": description_raw,
        }
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, sort_keys=True).encode("utf-8")).hexdigest()
