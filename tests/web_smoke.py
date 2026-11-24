"""Exercise the web flow against a freshly seeded local demo."""

import http.cookiejar
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from uuid import uuid4


BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
SANDBOX_SECRET = os.environ.get("COMMERCE_SANDBOX_WEBHOOK_SECRET")
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


def webhook(payload, timestamp=None, signature=None):
    body = json.dumps(payload, separators=(",", ":")).encode()
    timestamp = str(int(time.time()) if timestamp is None else timestamp)
    if signature is None:
        signature = hmac.new(SANDBOX_SECRET.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(BASE + "/payment-webhook.php", data=body, headers={
        "Content-Type": "application/json",
        "X-Commerce-Timestamp": timestamp,
        "X-Commerce-Signature": signature,
    })
    try:
        with urllib.request.urlopen(request, timeout=8) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        with error:
            return error.code, json.load(error)


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
second_device = Session()
second_device.login("buyer")
device_cart = second_device.load("/?page=cart")
assert "山形帆布托特包" in device_cart and "湖蓝陶瓷早餐杯" in device_cart
second_device.submit("/?page=cart", "cart", product=part_a, quantity=1,
                     revision=extract(device_cart, "revision"))
partial = buyer.submit("/?page=cart", "quote", channel="store", destination="测试自提点")
assert "¥80.00" in partial
second_device.submit(catalog_a, "cart", product=part_a, quantity=1)
stale = buyer.submit("/?page=cart", "place", token=extract(partial, "token"))
assert "购物车内容已变化" in stale
bad = buyer.submit("/?page=cart", "quote", csrf="invalid", channel="store", destination="测试自提点")
assert "表单已失效" in bad
quote = buyer.submit("/?page=cart", "quote", channel="store", destination="测试自提点")
assert "¥180.00" in quote
order = buyer.submit("/?page=cart", "place", token=extract(quote, "token"))
order_id = extract(order, "order")
assert "购物车为空" in second_device.load("/?page=cart")
if SANDBOX_SECRET:
    token_match = re.search(r"沙箱支付意图 <code>([a-f0-9]{32})</code>", order)
    assert token_match, "missing sandbox payment intent"
    payload = {"payment_token": token_match[1], "reference": f"web-{uuid4().hex}", "currency": "CNY", "amount": 18000}
    assert webhook(payload, signature="0" * 64) == (401, {"error": "invalid_signature"})
    assert webhook(payload, timestamp=int(time.time()) - 600) == (401, {"error": "invalid_signature"})
    status, accepted = webhook(payload)
    assert status == 200 and accepted["status"] == "accepted" and accepted["payment_id"]
    assert webhook(payload) == webhook(payload) == (200, accepted)
    assert webhook({**payload, "amount": 18001}) == (409, {"error": "receipt_conflict"})
    status, duplicate = webhook({**payload, "reference": f"duplicate-{uuid4().hex}"})
    assert status == 200 and duplicate["status"] == "refund_required"
    exceptions = finance.load("/?page=platform")
    assert "同订单重复实收" in exceptions
    assert str(duplicate["receipt_id"]) == extract(exceptions, "receipt")
    assert "已登记退回" in finance.submit("/?page=platform", "sandbox_refund", receipt=duplicate["receipt_id"],
                                          reference=f"refund-{uuid4().hex}")
    paid = buyer.load("/?page=orders")
else:
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
print("Web flow passed: login, role isolation, CSRF, shared cart, quote revisions, checkout, payment, fulfillment, refund, and settlement.")

suffix = uuid4().hex[:8]
shop_name = f"入驻测试 {suffix}"
owner_email = f"owner-{suffix}@example.test"
staff_email = f"staff-{suffix}@example.test"
password = "CommerceFixture-2026"
owner = Session()
owner.submit("/?page=account", "register", email=owner_email, password=password, shop=shop_name)
assert "确认协议并提交审核" in owner.load("/?page=merchant")
owner.submit("/?page=merchant", "shop_agreement", payee=shop_name)
platform = admin.load("/?page=platform")
match = re.search(rf'page=platform&amp;shop=(\d+)#ledger">{re.escape(shop_name)}</a>', platform)
assert match, "merchant application missing"
shop_id = match[1]
assert "marketplace-1" in platform
admin.submit("/?page=platform", "review", shop=shop_id, decision="approve")
assert "新增商品" in owner.load("/?page=merchant")

team = owner.submit("/?page=merchant", "shop_member", email=staff_email, password=password, position="catalog")
staff_id = extract(team, "member")
staff = Session()
staff.submit("/?page=account", "login", email=staff_email, password=password)
staff_page = staff.load("/?page=merchant")
assert "商品与库存" in staff_page and "订单履约" not in staff_page and "本店账单" not in staff_page
dormant_staff = Session()
dormant_staff.submit("/?page=account", "login", email=staff_email, password=password)
owner.submit("/?page=merchant", "shop_member_access", member=staff_id, enabled=0)
assert "该店铺账号已停用" in staff.load("/?page=merchant")
owner.submit("/?page=merchant", "shop_member_access", member=staff_id, enabled=1)
assert "店铺授权已变更，请重新登录" in dormant_staff.load("/?page=merchant")

admin.submit("/?page=platform", "shop_status", shop=shop_id, decision="suspend", reason="验收盘点")
closed = owner.load("/?page=merchant")
assert "暂停接单" in closed and "本店账单" in closed and "新增商品" in closed
admin.submit("/?page=platform", "shop_status", shop=shop_id, decision="resume", reason="盘点完成")
assert "新增商品" in owner.load("/?page=merchant")
print("Merchant web flow passed: application, approval, staff roles, session revocation, suspension, and reopening.")
