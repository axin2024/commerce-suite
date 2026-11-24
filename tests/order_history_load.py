"""Verify and time the authenticated merchant order history on a local instance."""

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import html
from http.cookiejar import CookieJar
import json
import math
from pathlib import Path
import re
import threading
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPCookieProcessor, build_opener


def require(condition, message):
    if not condition:
        raise ValueError(message)


def history_ids(body):
    require("<h1>店铺订单</h1>" in body, "merchant order history was not rendered")
    return [int(value) for value in re.findall(r'<th scope="row">#(\d+)</th>', body)]


def history_url(filters, **extra):
    return "/?" + urlencode({
        "page": "merchant_orders",
        "from": filters["from"],
        "through": filters["through"],
        **extra,
    })


def login(base, email):
    client = build_opener(HTTPCookieProcessor(CookieJar()))
    account = base + "/?page=account"
    with client.open(account, timeout=8) as response:
        csrf = re.search(r'name="csrf" value="([^"]+)"', response.read().decode())[1]
    values = urlencode({
        "csrf": csrf,
        "action": "login",
        "email": email,
        "password": "CommerceCapacity-2026",
    })
    with client.open(base + "/", values.encode(), timeout=8) as response:
        response.read()
    with client.open(account, timeout=8) as response:
        require(email in response.read().decode(), "merchant login failed")
    return client


def get(client, base, path):
    with client.open(base + path, timeout=8) as response:
        return response.read().decode()


def expect_status(client, base, path, status):
    try:
        get(client, base, path)
    except HTTPError as error:
        with error:
            require(error.code == status, f"expected HTTP {status}, got {error.code}")
    else:
        raise ValueError(f"expected HTTP {status}, got a successful response")


def check_pages(base, primary, fixtures):
    main = fixtures["scenarios"]["history"]
    first = get(primary, base, history_url(main))
    require(history_ids(first) == main["expected_ids"][:50], "first page did not match persisted orders")
    next_page = re.search(r'<a href="([^"]+)">下一页</a>', first)
    require(next_page is not None, "missing next page")
    second = get(primary, base, html.unescape(next_page[1]))
    require(history_ids(second) == main["expected_ids"][50:100], "second page skipped or repeated orders")
    other = fixtures["tenants"]["other"]
    forced = get(primary, base, history_url(main, shop_id=other["shop_id"]))
    require(history_ids(forced) == main["expected_ids"][:50], "query parameter changed tenant")
    second_shop = login(base, other["email"])
    ids = history_ids(get(second_shop, base, history_url(main)))
    require(len(ids) == 50 and set(ids).isdisjoint(main["expected_ids"]), "tenant isolation failed")
    expect_status(primary, base, history_url(main, before="2021-01-01 12:00:00|1"), 400)
    expect_status(primary, base, history_url(main, through="2026-02-30"), 400)
    expect_status(build_opener(), base, history_url(main), 403)
    return ["first_page", "second_page", "tenant_scope", "invalid_cursor", "invalid_date", "anonymous"]


def metrics(values):
    if not values:
        return {"count": 0}
    ordered = sorted(values)
    return {
        "count": len(values),
        "p50_ms": ordered[math.ceil(len(ordered) * 0.50) - 1],
        "p95_ms": ordered[math.ceil(len(ordered) * 0.95) - 1],
        "p99_ms": ordered[math.ceil(len(ordered) * 0.99) - 1],
        "max_ms": ordered[-1],
    }


def sample(client, base, scenario):
    name, path, expected = scenario
    started = time.perf_counter()
    try:
        body = get(client, base, path)
        elapsed = round((time.perf_counter() - started) * 1000, 3)
        require(history_ids(body) == expected, "unexpected order IDs")
        return name, elapsed, None
    except HTTPError as error:
        with error:
            return name, None, f"http_{error.code}"
    except (URLError, TimeoutError, OSError) as error:
        return name, None, type(error).__name__
    except ValueError:
        return name, None, "unexpected_body"


def run(base, fixture, workers, seconds, warmups):
    primary_email = fixture["tenants"]["main"]["email"]
    clients = [login(base, primary_email) for _ in range(workers)]
    checks = check_pages(base, clients[0], fixture)
    scenarios = [(name, history_url(spec), spec["expected_ids"][:50])
                 for name, spec in fixture["scenarios"].items()]
    started = time.perf_counter()
    for client in clients:
        for _ in range(warmups):
            for scenario in scenarios:
                _, _, error = sample(client, base, scenario)
                require(error is None, f"warmup failed with {error}")
    warmup_duration = round(time.perf_counter() - started, 3)

    start = 0

    def set_start():
        nonlocal start
        start = time.perf_counter()

    barrier = threading.Barrier(workers, action=set_start)

    def worker(index):
        results = []
        barrier.wait()
        while time.perf_counter() < start + seconds:
            scenario = scenarios[(index + len(results)) % len(scenarios)]
            results.append(sample(clients[index], base, scenario))
        return results

    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = [item for group in pool.map(worker, range(workers)) for item in group]
    measured_duration = round(time.perf_counter() - start, 3)
    errors = Counter(error for _, _, error in results if error)
    samples = {name: [] for name, _, _ in scenarios}
    for name, elapsed, error in results:
        if error is None:
            samples[name].append(elapsed)
    by_scenario = {name: metrics(values) for name, values in samples.items()}
    overall = metrics([value for values in samples.values() for value in values])
    passed = not errors and all(0 < entry.get("p95_ms", 0) <= 2000 for entry in by_scenario.values())
    return {
        "run": fixture["run"],
        "status": "passed" if passed else "failed",
        "preflight_checks": checks,
        "workers": workers,
        "stable_window_seconds": seconds,
        "measured_seconds": measured_duration,
        "warmup_requests": workers * warmups * len(scenarios),
        "warmup_seconds": warmup_duration,
        "requests_per_second": round(len(results) / measured_duration, 3),
        "errors": errors,
        "overall": overall,
        "by_scenario": by_scenario,
        "samples_ms": samples,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("base")
    parser.add_argument("fixture", type=Path)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--seconds", type=int, default=30)
    parser.add_argument("--warmups", type=int, default=2)
    args = parser.parse_args()
    parsed = urlsplit(args.base)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost", "::1"):
        parser.error("use a local HTTP instance")
    if args.workers < 1 or args.seconds < 1 or args.warmups < 1:
        parser.error("workers, seconds, and warmups must be positive")
    fixture = json.loads(args.fixture.read_text(encoding="utf-8-sig"))
    result = run(args.base.rstrip("/"), fixture, args.workers, args.seconds, args.warmups)
    print(json.dumps(result, ensure_ascii=True, indent=2))
    return int(result["status"] != "passed")


if __name__ == "__main__":
    raise SystemExit(main())
