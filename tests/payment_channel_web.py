"""Exercise the signed bridge contract against a seeded instance with APP_DEMO=0."""

import hashlib
import hmac
import html
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from uuid import uuid4

from web_support import BASE, Session, extract, forms, pickup_choices


def event(payload, provider="bridge_v1", signature=None, timestamp=None, method="POST"):
    body = json.dumps(payload, separators=(",", ":")).encode()
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    if signature is None:
        signature = hmac.new(webhook_secret, stamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(BASE + "/channel-webhook.php?provider=" + provider, method=method,
                                     data=body if method == "POST" else None, headers={
                                         "Content-Type": "application/json",
                                         "X-Bridge-Timestamp": stamp, "X-Bridge-Signature": signature,
                                     })
    try:
        with urllib.request.urlopen(request, timeout=8) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        with error:
            return error.code, json.load(error)


checkout_secret = os.environ["COMMERCE_BRIDGE_CHECKOUT_SECRET"].encode()
webhook_secret = os.environ["COMMERCE_BRIDGE_WEBHOOK_SECRET"].encode()
checkout_base = os.environ["COMMERCE_BRIDGE_CHECKOUT_URL"]
buyer, north, finance, other = (Session() for _ in range(4))
buyer.login("buyer")
north.login("north")
finance.login("finance")
other.submit("/?page=account", "register", email=f"bridge-{uuid4().hex}@example.test", password="TestPass-Bridge-2026")
catalog = "/?q=NORTH-01"
product = extract(buyer.load(catalog), "product")


def place():
    buyer.submit(catalog, "cart", product=product, quantity=1)
    cart = buyer.load("/?page=cart")
    quote = buyer.submit("/?page=cart", "quote", channel="store", **pickup_choices(cart))
    placed = buyer.submit("/?page=cart", "place", token=extract(quote, "token"))
    order = extract(forms(placed, "channel_pay")[0], "order")
    assert "模拟支付" not in placed and "沙箱支付意图" not in placed
    assert "订单当前无法发起渠道支付" in other.submit("/?page=account", "channel_pay", order=order)
    link_page = buyer.submit("/?page=orders", "channel_pay", order=order)
    link = html.unescape(re.search(r'href="([^"]+)" rel="noreferrer">前往支付渠道', link_page)[1])
    endpoint, query = link.split("?", 1)
    assert endpoint == checkout_base
    signed_query, signature = query.rsplit("&signature=", 1)
    assert hmac.compare_digest(signature, hmac.new(checkout_secret, signed_query.encode(), hashlib.sha256).hexdigest())
    intent = dict(urllib.parse.parse_qsl(signed_query))
    assert set(intent) == {"payment_token", "amount", "currency", "expires_at"}
    assert intent["currency"] == "CNY" and intent["amount"] == "12000"
    assert 0 < int(intent["expires_at"]) - int(time.time()) <= 900
    return order, intent["payment_token"]


order, token = place()
capture = {"type": "capture", "token": token, "reference": "bridge-capture-001", "currency": "CNY", "amount": 12000}
assert event(capture, provider="unknown") == (404, {"error": "not_found"})
assert event(capture, method="GET") == (405, {"error": "method_not_allowed"})
assert event(capture, signature="0" * 64) == (401, {"error": "invalid_signature"})
assert event(capture, timestamp=int(time.time()) - 601) == (401, {"error": "invalid_signature"})
assert event({**capture, "amount": "12000"}) == (422, {"error": "invalid_event"})
status, wrong_amount = event({**capture, "reference": "bridge-mismatch-001", "amount": 12001})
assert status == 200 and wrong_amount["status"] == "needs_review" and wrong_amount["payment_id"] is None
assert "待付款" in buyer.load("/?page=orders")
status, accepted = event(capture)
assert status == 200 and accepted["status"] == "accepted" and accepted["payment_id"]
assert event(dict(reversed(capture.items()))) == event(capture) == (200, accepted)
assert event({**capture, "amount": 12001}) == (409, {"error": "receipt_conflict"})
assert "已付款" in buyer.load("/?page=orders")
status, duplicate = event({**capture, "reference": "bridge-duplicate-001"})
assert status == 200 and duplicate["status"] == "refund_required" and duplicate["payment_id"] is None

prepare, = forms(north.load("/?page=merchant"), "pickup_prepare")
suborder = extract(prepare, "merchant_order")
location = extract(prepare, "pickup_location")
assert "待提货" in north.submit("/?page=merchant", "pickup_prepare", merchant_order=suborder, pickup_location=location)
pickup = buyer.submit("/?page=orders", "pickup_issue", merchant_order=suborder)
code = re.search(r"提货码 <code>([A-F0-9]{16})</code>", pickup)[1]
assert "已履约" in north.submit(f"/?page=merchant&pickup_order={suborder}", "pickup_redeem",
                              merchant_order=suborder, pickup_location=location, code=code)

history = buyer.load("/?page=orders")
buyer.submit("/?page=orders", "refund", item=extract(history, "item"), key=extract(history, "key"),
             quantity=1, reason="桥接支付退货验收")
review, = forms(north.load("/?page=merchant"), "return_approve")
refund_id = extract(review, "refund")
warehouse = re.search(r'<option value="(\d+)">主仓', review)[1]
north.submit("/?page=merchant", "return_approve", refund=refund_id, warehouse=warehouse, address="演示退货窗口")
buyer.submit("/?page=orders", "return_dispatch", refund=refund_id, carrier="demo", tracking="bridge-return-parcel")
assert "待渠道退款" in north.submit("/?page=merchant", "return_inspect", refund=refund_id, accepted=1, restocked=1)
queue = finance.load(f"/?page=platform&return_receipt={refund_id}")
refund_token = re.search(r'<code class="return-token">([a-f0-9]{32})</code>', queue)[1]
assert "仅可确认现金退款为零" in finance.submit("/?page=account", "confirm_zero_return", refund=refund_id)
refund = {"type": "return_refund", "token": refund_token, "reference": "bridge-refund-001",
          "payment_reference": capture["reference"], "currency": "CNY", "amount": 12000}
assert event({**refund, "amount": 11999}) == (409, {"error": "receipt_conflict"})
assert event(refund) == event(refund) == (200, {"status": "refunded", "refund_id": int(refund_id)})
assert refund["reference"] in buyer.load("/?page=orders")
assert "退款冲正" in finance.load("/?page=platform")

late_order, late_token = place()
buyer.submit("/?page=orders", "cancel", order=late_order)
late = {**capture, "reference": "bridge-late-001", "token": late_token}
status, pending_return = event(late)
assert status == 200 and pending_return["status"] == "refund_required" and pending_return["payment_id"] is None
late_refund = {"type": "exception_refund", "reference": "bridge-refund-002", "payment_reference": late["reference"],
               "currency": "CNY", "amount": 12000}
assert event({**late_refund, "reference": refund["reference"]}) == (409, {"error": "receipt_conflict"})
assert event(late_refund) == event(late_refund) == (
    200, {"receipt_id": pending_return["receipt_id"], "status": "refunded"}
)

bill = "\n".join((
    "kind,order_id,reference,currency,amount_minor,provider",
    f"capture,{order},{capture['reference']},CNY,12000,bridge_v1",
    f"capture,{order},{capture['reference']},CNY,12000,sandbox",
    f"return_refund,{order},{refund['reference']},CNY,12000,bridge_v1",
    f"exception_refund,{late_order},{late_refund['reference']},CNY,12000,bridge_v1",
))
query, _ = finance.upload_bill(bill)
php = ["php", "-d", "extension=pdo_mysql"] if os.name == "nt" else ["php"]
subprocess.run([*php, "bin/commerce.php", "reconcile"], check=True, timeout=15)
details = finance.load("/?" + query)
assert details.count("本地回执 #") == 3 and "待补回执" in details
assert "已取消" in buyer.load("/?page=orders")
print("Payment channel web flow passed: signed checkout, capture, replay, return, late capture, and scoped reconciliation.")
