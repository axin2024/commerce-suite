"""Check carrier tracking through the production-mode web entry points."""

import hashlib
import hmac
import html
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from uuid import uuid4

from web_support import BASE, Session, extract, forms


def send(path, header_prefix, secret, payload, signature=None, timestamp=None, method="POST"):
    body = json.dumps(payload, separators=(",", ":")).encode()
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    if signature is None:
        signature = hmac.new(secret, stamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(BASE + path, data=body if method == "POST" else None, method=method, headers={
        "Content-Type": "application/json",
        f"X-{header_prefix}-Timestamp": stamp,
        f"X-{header_prefix}-Signature": signature,
    })
    try:
        with urllib.request.urlopen(request, timeout=8) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        with error:
            return error.code, json.load(error)


carrier_secret = os.environ["COMMERCE_CARRIER_WEBHOOK_SECRET"].encode()
payment_secret = os.environ["COMMERCE_BRIDGE_WEBHOOK_SECRET"].encode()


def carrier_event(payload, carrier="parcel_v1", **kwargs):
    return send(f"/carrier-webhook.php?carrier={carrier}", "Carrier", carrier_secret, payload, **kwargs)


buyer, north, pond = (Session() for _ in range(3))
north.login("north")
pond.login("pond")
tag = uuid4().hex[:12]
email = f"carrier-{tag}@example.test"
buyer.submit("/?page=account", "register", email=email, password="TestPass-Carrier-2026")
assert email in buyer.load("/?page=account")
catalog = "/?q=NORTH-01"
product = extract(buyer.load(catalog), "product")
buyer.submit(catalog, "cart", product=product, quantity=2)
quote = buyer.submit("/?page=cart", "quote", channel="web", destination="测试包裹配送地址")
placed = buyer.submit("/?page=cart", "place", token=extract(quote, "token"))
order = extract(forms(placed, "channel_pay")[0], "order")
link_page = buyer.submit("/?page=orders", "channel_pay", order=order)
checkout_url = html.unescape(re.search(r'href="([^"]+)" rel="noreferrer">前往支付渠道', link_page)[1])
intent = dict(urllib.parse.parse_qsl(urllib.parse.urlsplit(checkout_url).query))
capture = {"type": "capture", "token": intent["payment_token"], "reference": f"payment-{tag}",
           "currency": "CNY", "amount": 24800}
assert intent["amount"] == "24800" and "模拟支付" not in placed
status, paid = send("/channel-webhook.php?provider=bridge_v1", "Bridge", payment_secret, capture)
assert status == 200 and paid["status"] == "accepted" and paid["payment_id"]


def dispatch(tracking):
    form, = forms(north.load("/?page=merchant"), "shipment_dispatch")
    suborder = extract(form, "merchant_order")
    quantity_name = re.search(r'name="(quantities\[\d+\])"', form)[1]
    page = north.submit("/?page=merchant", "shipment_dispatch", csrf=extract(form, "csrf"),
                        merchant_order=suborder, warehouse=extract(form, "warehouse"), key=extract(form, "key"),
                        carrier="parcel_v1", tracking=tracking, **{quantity_name: 1})
    assert tracking in page
    return suborder, page


tracking_a, tracking_b = f"TRACK-A-{tag}", f"TRACK-B-{tag}"
suborder, partial = dispatch(tracking_a)
assert "部分发货" in partial
assert dispatch(tracking_b)[0] == suborder
assert tracking_a not in pond.load("/?page=merchant") and tracking_b not in pond.load("/?page=merchant")

now = int(time.time())
transit = {"type": "in_transit", "tracking": tracking_a, "reference": f"transit-{tag}", "occurred_at": now - 30}
assert carrier_event(transit, carrier="unconfigured") == (404, {"error": "not_found"})
assert carrier_event(transit, method="GET") == (405, {"error": "method_not_allowed"})
assert carrier_event(transit, signature="0" * 64) == (401, {"error": "invalid_signature"})
assert carrier_event(transit, timestamp=now - 600) == (401, {"error": "invalid_signature"})
assert carrier_event({**transit, "occurred_at": now + 600}) == (422, {"error": "invalid_event"})
assert carrier_event({**transit, "type": "unknown"}) == (422, {"error": "invalid_event"})
assert carrier_event({**transit, "tracking": "MISSING"}) == (409, {"error": "tracking_conflict"})
assert send("/shipping-webhook.php", "Commerce", carrier_secret, {
    "carrier": "parcel_v1", "tracking": tracking_a, "event_reference": "sandbox-attempt",
}) == (404, {"error": "not_found"})

status, recorded = carrier_event(transit)
assert status == 200 and recorded["status"] == "in_transit"
assert "运输中" in buyer.load("/?page=orders")
assert carrier_event(dict(reversed(transit.items()))) == (200, recorded)
assert carrier_event({**transit, "tracking": tracking_b}) == (409, {"error": "tracking_conflict"})
assert carrier_event({**transit, "occurred_at": now - 29}) == (409, {"error": "tracking_conflict"})
assert carrier_event({**transit, "type": "exception"}) == (409, {"error": "tracking_conflict"})

delivery = {**transit, "type": "out_for_delivery", "reference": f"route-{tag}", "occurred_at": now - 5}
assert carrier_event(delivery)[0] == 200 and "派送中" in north.load("/?page=merchant")
delayed = {**transit, "type": "exception", "reference": f"delay-{tag}", "occurred_at": now - 10}
assert carrier_event(delayed)[0] == 200
tracking_page = buyer.load("/?page=orders")
assert "派送中" in tracking_page and "配送异常" not in tracking_page
exception = {**delayed, "reference": f"exception-{tag}", "occurred_at": now - 1}
assert carrier_event(exception)[0] == 200 and "配送异常" in buyer.load("/?page=orders")


def sales_for_suborder():
    ledger = north.load("/?page=merchant").split('id="finance"', 1)[1]
    return re.findall(rf'<tr><td>#{suborder}</td><td>销售</td>.*?</tr>', ledger, re.S)


delivered_a = {**transit, "type": "delivered", "reference": f"delivered-a-{tag}", "occurred_at": now}
delivered_b = {**delivered_a, "tracking": tracking_b, "reference": f"delivered-b-{tag}"}
assert carrier_event(delivered_a)[0] == 200
assert "待签收" in buyer.load("/?page=orders") and not sales_for_suborder()
assert carrier_event({**exception, "reference": f"after-{tag}", "occurred_at": now + 1})[0] == 200
tracking_page = buyer.load("/?page=orders")
assert "配送异常" not in tracking_page and "待签收" in tracking_page
status, last = carrier_event(delivered_b)
assert status == 200 and last["status"] == "delivered" and last["shipment_id"] != recorded["shipment_id"]
assert carrier_event(delivered_b) == (200, last)
assert carrier_event({**delivered_b, "reference": f"second-confirmation-{tag}"})[0] == 200
fulfilled = buyer.load("/?page=orders")
assert fulfilled.count("已签收") == 2 and "已履约" in fulfilled
sale, = sales_for_suborder()
assert "¥248.00" in sale and "¥24.00" in sale and "¥224.00" in sale
print("Carrier web flow passed: signed tracking, out-of-order updates, parcel completion, and a single sale entry.")
