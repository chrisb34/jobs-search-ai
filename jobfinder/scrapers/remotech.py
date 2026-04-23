"""Remotech.ai scraper."""

from __future__ import annotations

import hashlib
import json
import re
import time
from dataclasses import dataclass
from html import unescape
from typing import Any, Iterator
from urllib.parse import parse_qs, urljoin, urlparse

import requests
from bs4 import BeautifulSoup


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)
REMOTECH_BASE_URL = "https://www.remotech.ai"

EUROPE_LOCATION_TERMS = {
    "europe",
    "emea",
    "uk",
    "united kingdom",
    "ireland",
    "france",
    "germany",
    "netherlands",
    "spain",
    "portugal",
    "sweden",
    "switzerland",
    "denmark",
    "estonia",
    "poland",
    "slovenia",
    "greece",
    "republic of ireland",
}
NON_EUROPE_ONLY_TERMS = {
    "usa",
    "united states",
    "canada",
    "india",
    "brazil",
    "mexico",
    "argentina",
    "pakistan",
    "nigeria",
    "kenya",
    "egypt",
    "ghana",
    "bangladesh",
    "turkey",
}
SKIP_ROLE_TERMS = {
    "data annotation",
    "analytics & data science",
    "product management",
    "ui / ux design",
}


@dataclass
class RemotechSearchConfig:
    search_url: str
    path: str
    page: int = 1

    @classmethod
    def from_search_url(cls, search_url: str) -> "RemotechSearchConfig":
        parsed = urlparse(search_url)
        params = parse_qs(parsed.query)
        page = 1
        for values in params.values():
            for value in values:
                if value.isdigit():
                    page = max(int(value), 1)
                    break
        return cls(search_url=search_url, path=parsed.path or "/remote-jobs/europe", page=page)

    def page_url(self, page: int) -> str:
        return self.search_url if page == self.page else urljoin(REMOTECH_BASE_URL, self.path)


class RemotechScraper:
    source = "remotech"

    def __init__(self, config: RemotechSearchConfig, delay_seconds: float = 0.5) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": "en-US,en;q=0.9",
            }
        )

    def scrape(self, max_pages: int = 1, auto_pages: bool = False) -> list[dict]:
        jobs: list[dict] = []
        seen_ids: set[str] = set()

        page_url = self.config.page_url(self.config.page)

        for _ in range(max_pages):
            page_html = self._fetch(page_url)
            cards = list(self._parse_listing_cards(page_html))
            if not cards:
                break

            new_cards = 0
            for card in cards:
                if card["source_job_id"] in seen_ids or not self._is_relevant_listing(card):
                    continue
                detail = self._fetch_job_detail(card)
                if not self._is_relevant_detail(card, detail):
                    seen_ids.add(card["source_job_id"])
                    continue
                jobs.append(self._build_raw_job(card, detail))
                seen_ids.add(card["source_job_id"])
                new_cards += 1
                if self.delay_seconds:
                    time.sleep(self.delay_seconds)

            next_page_url = self._next_page_url(page_html)
            if new_cards == 0 or next_page_url is None or next_page_url == page_url:
                break
            page_url = next_page_url

        return jobs

    def _discover_pages(self, page_html: str) -> list[int]:
        soup = BeautifulSoup(page_html, "html.parser")
        pages = {self.config.page}
        for a in soup.select("a[href*='_page='], a[href*='page=']"):
            href = a.get("href") or ""
            for match in re.finditer(r"(?:_page|page)=(\d+)", href):
                pages.add(int(match.group(1)))
        return sorted(pages)

    def _next_page_url(self, page_html: str) -> str | None:
        soup = BeautifulSoup(page_html, "html.parser")
        link = soup.select_one("a.w-pagination-next[href]")
        if link is None:
            return None
        href = link.get("href") or ""
        if not href:
            return None
        return urljoin(REMOTECH_BASE_URL, href)

    def _fetch(self, url: str) -> str:
        response = self.session.get(url, timeout=30)
        response.raise_for_status()
        return response.text

    def _parse_listing_cards(self, page_html: str) -> Iterator[dict[str, Any]]:
        soup = BeautifulSoup(page_html, "html.parser")
        for item in soup.select(".job_item"):
            link = item.select_one("a.job-link-block[href]")
            title_node = item.select_one(".job-title-job-list, .text-block-48")
            company_node = item.select_one(".text-block-9")
            location_node = item.select_one("[data-location-text], [fs-cmsfilter-field='Location'], .text-block-5, .text-block-2")
            if link is None or title_node is None:
                continue
            href = link.get("href") or ""
            if not href.startswith("/jobs/"):
                continue
            job_url = urljoin(REMOTECH_BASE_URL, href)
            source_job_id = href.rstrip("/").split("/")[-1]
            right_values = [node.get_text(" ", strip=True) for node in item.select(".job-item-right-block > div")]
            yield {
                "source_job_id": source_job_id,
                "url": job_url,
                "title": title_node.get_text(" ", strip=True),
                "company": company_node.get_text(" ", strip=True) if company_node else None,
                "location_raw": location_node.get_text(" ", strip=True) if location_node else None,
                "contract_raw": self._first_matching(right_values, {"full time", "contractor", "part time"}),
                "experience_raw": self._first_matching_fragment(right_values, "year"),
                "role_raw": None,
                "listing_meta": right_values,
            }

    def _fetch_job_detail(self, card: dict[str, Any]) -> dict[str, Any]:
        page_html = self._fetch(card["url"])
        soup = BeautifulSoup(page_html, "html.parser")
        return {
            "title": self._text(soup, ".job-title") or card.get("title"),
            "company": self._text(soup, ".jdcompanyname") or card.get("company"),
            "location_raw": self._text(soup, ".location") or card.get("location_raw"),
            "contract_raw": self._text(soup, ".type") or card.get("contract_raw"),
            "experience_raw": self._text(soup, ".experience") or card.get("experience_raw"),
            "salary_raw": self._text(soup, ".text-block-25"),
            "role_raw": self._text(soup, ".role") or card.get("role_raw"),
            "skills": self._skills(soup),
            "description_raw": self._description(soup),
            "apply_url": self._apply_url(soup),
            "posted_at_raw": self._posted_at_raw(soup),
        }

    def _build_raw_job(self, card: dict[str, Any], detail: dict[str, Any]) -> dict[str, Any]:
        title = detail.get("title") or card.get("title")
        company = detail.get("company") or card.get("company")
        location_raw = detail.get("location_raw") or card.get("location_raw")
        contract_raw = detail.get("contract_raw") or card.get("contract_raw")
        salary = self._parse_salary(detail.get("salary_raw"))
        skills = detail.get("skills")
        extra = {
            "country": self._country(location_raw),
            "city": None,
            "job_location": {
                "addressCountry": self._country(location_raw),
                "addressLocality": location_raw,
            },
            "job_location_type": "TELECOMMUTE",
            "salary": salary,
            "language": "en",
            "skills": skills,
            "role": detail.get("role_raw") or card.get("role_raw"),
            "experience": detail.get("experience_raw") or card.get("experience_raw"),
            "apply_url": detail.get("apply_url"),
            "listing_meta": card.get("listing_meta"),
        }
        return {
            "source": self.source,
            "source_job_id": card["source_job_id"],
            "url": card["url"],
            "title": title,
            "company": company,
            "location_raw": location_raw,
            "description_raw": detail.get("description_raw"),
            "salary_raw": json.dumps(salary, ensure_ascii=True, sort_keys=True) if salary else detail.get("salary_raw"),
            "contract_raw": contract_raw,
            "remote_raw": "remote",
            "posted_at_raw": detail.get("posted_at_raw"),
            "listed_at": detail.get("posted_at_raw"),
            "content_hash": self._content_hash(card, detail),
            "extra": extra,
        }

    def _is_relevant_listing(self, card: dict[str, Any]) -> bool:
        role = (card.get("role_raw") or "").lower()
        if role in SKIP_ROLE_TERMS:
            return False

        location = (card.get("location_raw") or "").lower()
        if any(term in location for term in EUROPE_LOCATION_TERMS):
            return True
        if any(term in location for term in NON_EUROPE_ONLY_TERMS):
            return False
        return "remote" in location

    def _is_relevant_detail(self, card: dict[str, Any], detail: dict[str, Any]) -> bool:
        role = (detail.get("role_raw") or card.get("role_raw") or "").lower()
        if role in SKIP_ROLE_TERMS:
            return False

        location = detail.get("location_raw") or card.get("location_raw")
        return self._is_target_location(location)

    def _is_target_location(self, location_raw: str | None) -> bool:
        location = (location_raw or "").lower()
        if any(term in location for term in EUROPE_LOCATION_TERMS):
            return True
        if any(term in location for term in NON_EUROPE_ONLY_TERMS):
            return False
        return False

    def _text(self, soup: BeautifulSoup, selector: str) -> str | None:
        node = soup.select_one(selector)
        if node is None:
            return None
        text = node.get_text(" ", strip=True)
        return text or None

    def _description(self, soup: BeautifulSoup) -> str | None:
        node = soup.select_one(".job_description")
        if node is None:
            return None
        text = node.get_text("\n", strip=True)
        return text or None

    def _skills(self, soup: BeautifulSoup) -> list[str] | None:
        node = soup.select_one(".skills")
        if node is None:
            return None
        skills = [skill.strip() for skill in re.split(r",|\n", node.get_text(" ", strip=True)) if skill.strip()]
        return skills or None

    def _apply_url(self, soup: BeautifulSoup) -> str | None:
        for link in soup.select("a.apply-button_sm[href]"):
            href = link.get("href") or ""
            if href and not href.startswith("/apply-form"):
                return unescape(href)
        return None

    def _posted_at_raw(self, soup: BeautifulSoup) -> str | None:
        type_node = soup.select_one(".type[date-created]")
        if type_node is None:
            return None
        posted_at = type_node.get("date-created")
        return posted_at.strip() if posted_at else None

    def _first_matching(self, values: list[str], allowed: set[str]) -> str | None:
        for value in values:
            if value.lower() in allowed:
                return value
        return None

    def _first_matching_fragment(self, values: list[str], fragment: str) -> str | None:
        for value in values:
            if fragment in value.lower():
                return value
        return None

    def _country(self, location_raw: str | None) -> str | None:
        if not location_raw:
            return None
        lowered = location_raw.lower()
        for country in sorted(EUROPE_LOCATION_TERMS, key=len, reverse=True):
            if country in lowered:
                return country.title()
        return "Europe" if "remote" in lowered else None

    def _parse_salary(self, salary_raw: str | None) -> dict[str, Any] | None:
        if not salary_raw:
            return None
        cleaned = salary_raw.replace(",", "")
        currency = None
        if "€" in cleaned:
            currency = "EUR"
        elif "£" in cleaned:
            currency = "GBP"
        elif "$" in cleaned:
            currency = "USD"
        numbers = [float(number) for number in re.findall(r"\d+(?:\.\d+)?", cleaned)]
        if not numbers and currency is None:
            return None
        return {
            "currency": currency,
            "min": min(numbers) if numbers else None,
            "max": max(numbers) if numbers else None,
            "period": "YEAR",
            "raw": salary_raw,
        }

    def _content_hash(self, card: dict[str, Any], detail: dict[str, Any]) -> str:
        payload = {
            "source_job_id": card["source_job_id"],
            "title": detail.get("title") or card.get("title"),
            "company": detail.get("company") or card.get("company"),
            "location_raw": detail.get("location_raw") or card.get("location_raw"),
            "description_raw": detail.get("description_raw"),
        }
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, sort_keys=True).encode("utf-8")).hexdigest()
