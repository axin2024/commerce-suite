import http.cookiejar
import re
import sys
import urllib.parse
import urllib.request
from uuid import uuid4


BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
if urllib.parse.urlsplit(BASE).hostname not in ("127.0.0.1", "localhost", "::1"):
    raise SystemExit("Run this check against a local test instance.")


class Session:
    def __init__(self):
        self.client = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def load(self, page):
        with self.client.open(BASE + page, timeout=8) as response:
            return response.read().decode("utf-8")

    def submit(self, page, action, csrf=None, **fields):
        if csrf is None:
            csrf = extract(self.load(page), "csrf")
        values = urllib.parse.urlencode({"csrf": csrf, "action": action, **fields})
        with self.client.open(BASE + "/", values.encode("utf-8"), timeout=8) as response:
            return response.read().decode("utf-8")

    def login(self, role):
        self.submit("/?page=account", "login", email=f"{role}@example.test", password="CommerceDemo-2026")
        assert f"{role}@example.test" in self.load("/?page=account"), f"{role} login"

    def upload_bill(self, csv_text, csrf=None, company=None):
        if csrf is None:
            csrf = extract(self.load("/?page=channel_bills"), "csrf")
        boundary = f"commerce-fixture-{uuid4().hex}"
        body = "\r\n".join((
            f"--{boundary}", 'Content-Disposition: form-data; name="csrf"', "", csrf,
            f"--{boundary}", 'Content-Disposition: form-data; name="action"', "", "procurement_import" if company else "bill_import",
            *([f"--{boundary}", 'Content-Disposition: form-data; name="company"', "", str(company)] if company else []),
            f"--{boundary}", 'Content-Disposition: form-data; name="bill"; filename="statement.csv"',
            "Content-Type: text/csv", "", csv_text, f"--{boundary}--", "",
        )).encode()
        request = urllib.request.Request(BASE + "/", data=body, headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
        })
        with self.client.open(request, timeout=8) as response:
            return urllib.parse.urlsplit(response.url).query, response.read().decode("utf-8")


def extract(html, name):
    match = re.search(rf'name="{name}" value="([^"]+)"', html)
    if not match:
        raise AssertionError(f"missing {name}")
    return match.group(1)


def forms(html, action):
    return [form for form in re.findall(r"<form [^>]*>.*?</form>", html, flags=re.S)
            if f'name="action" value="{action}"' in form]


def pickup_choices(cart):
    selections = re.findall(r'<select name="(pickup_locations\[\d+\])">(.*?)</select>', cart, flags=re.S)
    return {field: re.search(r'<option value="(\d+)"', options)[1] for field, options in selections}
