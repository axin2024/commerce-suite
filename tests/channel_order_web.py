"""Run against two local PHP processes sharing one seeded test database."""

import hashlib
import hmac
import json
import os
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

from web_support import BASE, Session, extract, forms


CHANNELS = "/?page=sales_channels"
MERCHANT = "/?page=merchant"
ORDERS = "/?page=orders"
SECOND = os.environ["COMMERCE_CHANNEL_TEST_SECOND_URL"]
assert SECOND != BASE and urllib.parse.urlsplit(SECOND).hostname == "127.0.0.1"


def request(url, payload=None, headers=None):
    req = urllib.request.Request(url, data=payload, headers=headers or {})
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, json.load(response), response.headers


def signed_event(url, prefix, secret_variable, payload, bad_signature=False):
    body = json.dumps(payload).encode()
    stamp = str(int(time.time()))
    secret = os.environ[secret_variable].encode()
    signature = "0" * 64 if bad_signature else hmac.new(secret, stamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    status, data, _ = request(BASE + url, body, {"Content-Type": "application/json",
                              f"X-{prefix}-Timestamp": stamp, f"X-{prefix}-Signature": signature})
    return status, data


def api(token, action=None, body=None, base=BASE, **query):
    if action is not None:
        query["action"] = action
    status, data, headers = request(base + "/channel-orders.php?" + urllib.parse.urlencode(query),
                                    None if body is None else json.dumps(body).encode(),
                                    {"Authorization": f"Bearer {token}", "Content-Type": "application/json"})
    assert headers.get("Cache-Control") == "no-store" and headers.get("Set-Cookie") is None
    if status == 401:
        assert headers.get("WWW-Authenticate") == "Bearer"
    return status, data


def create(owner, skus):
    page = owner.submit(CHANNELS, "catalog_channel_create", code="bridge-store", label="渠道订单验收")
    source = extract(forms(page, "catalog_channel_rotate")[0], "channel")
    catalog_key = re.search(r'<code class="catalog-credential">(cf1_[a-f0-9]{64})</code>', page)[1]
    url = f"{CHANNELS}&channel={source}"
    for sku in skus:
        page = owner.submit(url, "catalog_channel_listing", channel=source, sku=sku, enabled=1, revision=0)
        assert "操作已保存" in page
    return url, source, catalog_key


def issue(owner, url, source):
    page = owner.load(url)
    issue_form, = forms(page, "channel_order_issue")
    result = owner.submit(url, "channel_order_issue", channel=source, revision=extract(issue_form, "revision"))
    token = re.search(r'<code class="channel-order-credential">(co1_[a-f0-9]{64})</code>', result)[1]
    assert token not in owner.load(url)
    return token


def available(catalog_key, sku):
    status, body, _ = request(BASE + "/catalog-feed.php", headers={"Authorization": "Bearer " + catalog_key})
    assert status == 200
    return next(line["available_units"] for line in body["items"] if line["sku"] == sku)


def draft(sku, quantity=1):
    return {"items": [{"sku": sku, "quantity": quantity}], "destination": "验收路 1 号"}


def priced(token, data, reference):
    status, quote = api(token, "quote", data)
    assert status == 200, (status, quote)
    assert quote["currency"] == "CNY" and quote["total_minor"] == quote["subtotal_minor"] + quote["shipping_minor"]
    return {**data, "reference": reference, "quote_version": quote["quote_version"]}


def race(first, second):
    gate = threading.Barrier(2, timeout=5)

    def start(call):
        gate.wait()
        return call()

    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(start, call) for call in (first, second)]
        return [future.result(timeout=15) for future in futures]


north, pond, buyer, admin, finance = (Session() for _ in range(5))
for session, name in ((north, "north"), (pond, "pond"), (buyer, "buyer"), (admin, "admin"), (finance, "finance")):
    session.login(name)
north_url, source, catalog_key = create(north, ("NORTH-01", "NORTH-02"))
pond_url, pond_source, pond_catalog_key = create(pond, ("POND-01",))
orders_key = issue(north, north_url, source)
pond_key = issue(pond, pond_url, pond_source)
assert api(catalog_key, "quote", draft("NORTH-01"))[0] == 401
assert request(BASE + "/catalog-feed.php", headers={"Authorization": "Bearer " + orders_key})[0] == 401
assert api(orders_key, "quote", draft("POND-01")) == (409, {"error": "sku_unavailable"})
assert api(pond_key, "quote", draft("POND-02")) == (409, {"error": "sku_unavailable"})
assert api(orders_key, "place", {**draft("NORTH-01"), "paid": True})[0] == 422
assert api(orders_key, "quote", {**draft("NORTH-01"), "items": [
    {"sku": "NORTH-01", "quantity": 1}, {"sku": "north-01", "quantity": 1},
]}) == (422, {"error": "duplicate_sku"})

old = priced(orders_key, draft("NORTH-01"), "partner-ord-1")
freight, = forms(north.load(MERCHANT), "freight_publish")
assert "操作已保存" in north.submit(MERCHANT, "freight_publish", strategy="per_order_v1",
                                revision=extract(freight, "revision"), base_fee="9.00")
assert api(orders_key, "place", old) == (409, {"error": "quote_changed"})
assert available(catalog_key, "NORTH-01") == 36
original = priced(orders_key, draft("NORTH-01"), "partner-ord-1")
duplicate = race(lambda: api(orders_key, "place", original),
                 lambda: api(orders_key, "place", original, base=SECOND))
assert sorted(result[0] for result in duplicate) == [200, 201], duplicate
order = duplicate[0][1]
assert order == duplicate[1][1] and order["status"] == "unpaid"
assert order["subtotal_minor"] == 12000 and order["shipping_minor"] == 900
assert available(catalog_key, "NORTH-01") == 35
assert api(orders_key, "place", {**original, "destination": "另一处地址"}) == (409, {"error": "reference_conflict"})
assert api(pond_key, reference=original["reference"]) == (404, {"error": "order_not_found"})
assert api(pond_key, "cancel", {"reference": original["reference"]})[0] == 404
assert "partner-ord-1" in north.load("/?page=merchant_orders")
assert "partner-ord-1" not in pond.load("/?page=merchant_orders")
assert "partner-ord-1" not in buyer.load(ORDERS)
same_reference = priced(pond_key, draft("POND-01"), original["reference"])
status, other_order = api(pond_key, "place", same_reference)
assert status == 201 and other_order["order_id"] != order["order_id"]
assert api(pond_key, "cancel", {"reference": same_reference["reference"]})[0] == 200
assert api(orders_key, reference=original["reference"]) == (200, order)
print("External order idempotency and shop isolation passed.")

product_page = "/?q=NORTH-02"
product = extract(buyer.load(product_page), "product")
stock = next(f for f in forms(north.load(MERCHANT), "sku_stock") if extract(f, "product") == product)
assert "操作已保存" in north.submit(MERCHANT, "sku_stock", product=product, warehouse=extract(stock, "warehouse"),
                                version=extract(stock, "version"), stock=1)
one_left = priced(orders_key, draft("NORTH-02"), "last-unit")
buyer.submit(product_page, "cart", product=product, quantity=1)
cart = buyer.submit("/?page=cart", "quote", channel="web", destination="站内验收路 1 号")
buy = extract(cart, "token")
partner, mall = race(lambda: api(orders_key, "place", one_left, base=SECOND),
                    lambda: buyer.submit("/?page=cart", "place", token=buy))
assert available(catalog_key, "NORTH-02") == 0
if partner[0] == 201:
    assert "库存不足" in mall
    status, cancelled = api(orders_key, "cancel", {"reference": "last-unit"})
    assert status == 200 and cancelled["status"] == "cancelled"
    assert api(orders_key, "place", one_left) == (200, cancelled)
else:
    assert partner == (409, {"error": "order_unavailable"})
    assert "已创建，请在 15 分钟内完成支付" in mall
    buyer.submit(ORDERS, "cancel", order=extract(mall, "order"))
assert available(catalog_key, "NORTH-02") == 1
to_cancel = priced(orders_key, draft("NORTH-02"), "partner-cancel")
assert api(orders_key, "place", to_cancel)[0] == 201
status, cancelled = api(orders_key, "cancel", {"reference": "partner-cancel"})
assert status == 200 and cancelled["status"] == "cancelled"
assert api(orders_key, "cancel", {"reference": "partner-cancel"}) == (200, cancelled)
assert available(catalog_key, "NORTH-02") == 1
print("The mall and the external channel competed for the same final unit.")

status, link = api(orders_key, "checkout", {"reference": original["reference"]})
assert status == 200
parts = urllib.parse.urlsplit(link["checkout_url"])
expected = urllib.parse.urlsplit(os.environ["COMMERCE_BRIDGE_CHECKOUT_URL"])
assert (parts.scheme, parts.netloc, parts.path) == (expected.scheme, expected.netloc, expected.path)
signed_query, signature = parts.query.rsplit("&signature=", 1)
expected_signature = hmac.new(os.environ["COMMERCE_BRIDGE_CHECKOUT_SECRET"].encode(),
                              signed_query.encode(), hashlib.sha256).hexdigest()
assert hmac.compare_digest(signature, expected_signature)
intent = dict(urllib.parse.parse_qsl(signed_query))
assert int(intent["amount"]) == order["total_minor"]
payment = {"type": "capture", "token": intent["payment_token"], "reference": "external-order-capture",
           "currency": "CNY", "amount": order["total_minor"]}
hook = "/channel-webhook.php?provider=bridge_v1"
assert signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", payment, bad_signature=True)[0] == 401
first_status, receipt = signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", payment)
retry_status, retry = signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", payment)
assert first_status == retry_status == 200 and receipt == retry and receipt["status"] == "accepted"
status, paid = api(orders_key, reference=original["reference"])
assert status == 200 and paid["status"] == "paid" and paid["fulfillment_status"] == "ready"
assert available(catalog_key, "NORTH-01") == 35
assert api(orders_key, "cancel", {"reference": original["reference"]}) == (409, {"error": "order_already_paid"})
assert api(orders_key, "checkout", {"reference": original["reference"]}) == (409, {"error": "order_not_payable"})
return_request = {"reference": original["reference"], "return_reference": "partner-return-1",
                  "sku": "NORTH-01", "quantity": 1, "reason": "渠道退货验收"}
assert api(orders_key, "return_request", return_request) == (409, {"error": "order_not_fulfilled"})
merchant = north.load(MERCHANT)
assert "partner-ord-1" in merchant
dispatch = next(f for f in forms(merchant, "shipment_dispatch")
                if int(extract(f, "merchant_order")) == paid["merchant_order_id"])
quantity = re.search(r'name="(quantities\[\d+\])"', dispatch)[1]
assert "操作已保存" in north.submit(MERCHANT, "shipment_dispatch", merchant_order=extract(dispatch, "merchant_order"),
                                  warehouse=extract(dispatch, "warehouse"), key=extract(dispatch, "key"),
                                  carrier="parcel_v1", tracking="EXT-FIXTURE-1", **{quantity: 1})
assert api(orders_key, reference=original["reference"])[1]["fulfillment_status"] == "shipped"
delivery = {"type": "delivered", "tracking": "EXT-FIXTURE-1", "reference": "external-order-delivered",
            "occurred_at": int(time.time())}
delivery_hook = "/carrier-webhook.php?carrier=parcel_v1"
status, delivered = signed_event(delivery_hook, "Carrier", "COMMERCE_CARRIER_WEBHOOK_SECRET", delivery)
assert status == 200 and delivered["status"] == "delivered"
assert api(orders_key, reference=original["reference"])[1]["fulfillment_status"] == "fulfilled"
ledger = north.load(MERCHANT)
assert "¥129.00" in ledger and "¥12.00" in ledger and "¥117.00" in ledger
print("Signed platform capture, carrier delivery, and merchant ledger passed.")

shop = re.search(r'shop=(\d+)#ledger">北仓选物</a>', admin.load("/?page=platform"))[1]
admin.submit("/?page=platform", "shop_status", shop=shop, decision="suspend", reason="暂停新交易验收售后")
assert api(orders_key, "quote", draft("NORTH-02")) == (409, {"error": "shop_unavailable"})
assert api(orders_key, "return_request", {**return_request, "sku": "NORTH-02"}) == (404, {"error": "order_sku_not_found"})
race_result = race(lambda: api(orders_key, "return_request", return_request),
                   lambda: api(orders_key, "return_request", return_request, base=SECOND))
assert sorted(result[0] for result in race_result) == [200, 201], race_result
refund = race_result[0][1]
assert refund == race_result[1][1] and refund["status"] == "pending"
assert refund["refund_amount_minor"] is None and refund["return_address"] is None
assert api(orders_key, "return_request", {**return_request, "return_reference": "second-claim"}) == (
    409, {"error": "return_unavailable"})
assert api(orders_key, "return_request", {**return_request, "reason": "改了申请原因"}) == (
    409, {"error": "return_reference_conflict"})
keys = {name: return_request[name] for name in ("reference", "return_reference")}
assert api(pond_key, **keys) == (404, {"error": "return_not_found"})
assert api(orders_key, **{**keys, "reference": "partner-cancel"}) == (404, {"error": "return_not_found"})
tracking = {**keys, "carrier": "parcel_v1", "tracking": "EXT-RETURN-FIXTURE-1"}
assert api(orders_key, "return_dispatch", tracking) == (409, {"error": "return_not_dispatchable"})
assert api(pond_key, "return_dispatch", tracking) == (404, {"error": "return_not_found"})

return_id = refund["return_id"]
queue = north.load(MERCHANT)
assert "partner-return-1" in queue and "partner-return-1" not in pond.load(MERCHANT)
approve, = (f for f in forms(queue, "return_approve") if int(extract(f, "refund")) == return_id)
warehouse = re.search(r'<option value="(\d+)">主仓', approve)[1]
north.submit(MERCHANT, "return_approve", refund=return_id, warehouse=warehouse, address="渠道验收退货仓", note="请随货附单")
authorized = api(orders_key, **keys)
assert authorized[0] == 200 and authorized[1]["status"] == "return_authorized"
assert authorized[1]["return_address"] == "渠道验收退货仓" and authorized[1]["review_note"] == "请随货附单"
assert "操作已保存" in north.submit(MERCHANT, "return_freight", refund=return_id, freight="5.00", note="按退货协议承担")
status, dispatched = api(orders_key, "return_dispatch", tracking)
assert status == 200 and dispatched["status"] == "return_in_transit"
assert dispatched["tracking"] == tracking["tracking"]
assert api(orders_key, "return_dispatch", tracking) == (status, dispatched)
assert api(orders_key, "return_dispatch", {**tracking, "tracking": "DIFFERENT"}) == (409, {"error": "return_not_dispatchable"})
assert "操作已保存" in north.submit(MERCHANT, "return_inspect", refund=return_id, accepted=1, restocked=1, note="完好入库")
status, inspected = api(orders_key, **keys)
assert status == 200 and inspected["status"] == "refund_pending"
assert (inspected["accepted_quantity"], inspected["restocked_quantity"], inspected["refund_amount_minor"]) == (1, 1, 12500)
assert inspected["inspection_note"] == "完好入库" and inspected["refund_reference"] is None
assert inspected["confirmed_at"] is None and "refund_token" not in inspected

finance_queue = finance.load(f"/?page=platform&return_receipt={return_id}")
refund_token = re.search(r'<code class="return-token">([a-f0-9]{32})</code>', finance_queue)[1]
refund_event = {"type": "return_refund", "token": refund_token, "reference": "external-return-refund",
                "payment_reference": payment["reference"], "currency": "CNY", "amount": 12500}
assert signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", {**refund_event, "amount": 12499})[0] == 409
assert api(orders_key, **keys) == (200, inspected)
receipt = signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", refund_event)
assert receipt == signed_event(hook, "Bridge", "COMMERCE_BRIDGE_WEBHOOK_SECRET", refund_event)
assert receipt == (200, {"status": "refunded", "refund_id": return_id})
status, refunded = api(orders_key, **keys)
assert status == 200 and refunded["status"] == "refunded" and refunded["confirmed_at"] > 0
assert refunded["refund_reference"] == refund_event["reference"]
assert api(orders_key, "return_request", return_request) == (200, refunded)
assert api(orders_key, "return_dispatch", tracking) == (200, refunded)
assert "退款冲正" in north.load(MERCHANT)
admin.submit("/?page=platform", "shop_status", shop=shop, decision="resume", reason="售后验收结束")
assert available(catalog_key, "NORTH-01") == 36
print("The suspended shop handled channel return, restocking, and signed refund without duplicate effects.")

renewed = issue(north, north_url, source)
assert orders_key != renewed
assert api(orders_key, reference=original["reference"])[0] == 401
assert api(orders_key, **keys)[0] == 401 and api(renewed, **keys) == (200, refunded)
assert api(renewed, "place", original)[1]["fulfillment_status"] == "fulfilled"
disable, = forms(north.load(north_url), "channel_order_disable")
assert "未授权" in north.submit(north_url, "channel_order_disable", channel=source, revision=extract(disable, "revision"))
assert api(renewed, reference=original["reference"])[0] == 401
assert available(catalog_key, "NORTH-01") == 36 and available(pond_catalog_key, "POND-01") == 24
pond_revoke, = forms(pond.load(pond_url), "catalog_channel_revoke")
pond.submit(pond_url, "catalog_channel_revoke", channel=pond_source, generation=extract(pond_revoke, "generation"))
assert api(pond_key, reference=original["reference"])[0] == 401
print("Order key rotation, disabling, and parent channel revocation passed.")
