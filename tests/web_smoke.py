"""Exercise the web flow against a freshly seeded local demo."""

import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
if urllib.parse.urlsplit(BASE).hostname not in ("127.0.0.1", "localhost", "::1"):
    raise SystemExit("Run this check against a local demo instance.")


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


def extract(html, name):
    match = re.search(rf'name="{name}" value="([^"]+)"', html)
    if not match:
        raise AssertionError(f"missing {name}")
    return match.group(1)


buyer, north, pond, finance, admin = (Session() for _ in range(5))
for session, role in zip((buyer, north, pond, finance, admin), ("buyer", "north", "pond", "finance", "admin")):
    session.login(role)
assert "审计记录" in admin.load("/?page=platform")
assert "审计记录" not in finance.load("/?page=platform")
try:
    buyer.load("/?page=merchant")
except urllib.error.HTTPError as error:
    assert error.code == 403
else:
    raise AssertionError("buyer reached merchant page")

catalog_a = "/?q=NORTH-01"
catalog_b = "/?q=POND-01"
part_a = extract(buyer.load(catalog_a), "product")
part_b = extract(buyer.load(catalog_b), "product")
buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
buyer.submit(catalog_b, "cart", product=part_b, quantity=1)
bad = buyer.submit("/?page=cart", "quote", csrf="invalid", channel="store", destination="测试自提点")
assert "表单已失效" in bad
quote = buyer.submit("/?page=cart", "quote", channel="store", destination="测试自提点")
assert "¥180.00" in quote
order = buyer.submit("/?page=cart", "place", token=extract(quote, "token"))
order_id = extract(order, "order")
paid = buyer.submit("/?page=orders", "pay", order=order_id, amount="18000")
assert "已付款" in paid and "¥108.00" in paid and "¥72.00" in paid
for merchant in (north, pond):
    merchant_page = merchant.load("/?page=merchant")
    assert "测试自提点" in merchant_page
    fulfilled = merchant.submit("/?page=merchant", "fulfill",
                                merchant_order=extract(merchant_page, "merchant_order"), tracking="SMOKE-01")
    assert "已履约" in fulfilled

order = buyer.load("/?page=orders")
refund = buyer.submit("/?page=orders", "refund", item=extract(order, "item"),
                      key=extract(order, "key"), quantity=1, reason="测试部分退款")
assert "售后记录" in refund
refund_id = extract(north.load("/?page=merchant"), "refund")
assert "已审批" in north.submit("/?page=merchant", "decide", refund=refund_id, decision="approve")
settlement = finance.load("/?page=platform")
assert "退款冲正" in settlement and "¥64.80" in settlement and "−¥10.80" in settlement
assert "生成结算批次" in settlement
shop_id = extract(settlement, "shop")
finance.submit("/?page=platform", "settle", shop=shop_id)
print("Web flow passed: login, role isolation, CSRF, checkout, payment, fulfillment, refund, and settlement.")
