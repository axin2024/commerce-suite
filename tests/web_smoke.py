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
SHIPPING_SECRET = os.environ.get("COMMERCE_SANDBOX_SHIPPING_SECRET")
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


def forms(html, action):
    return [form for form in re.findall(r"<form [^>]*>.*?</form>", html, flags=re.S)
            if f'name="action" value="{action}"' in form]


def pickup_choices(cart):
    selections = re.findall(r'<select name="(pickup_locations\[\d+\])">(.*?)</select>', cart, flags=re.S)
    return {field: re.search(r'<option value="(\d+)"', options)[1] for field, options in selections}


def webhook(payload, timestamp=None, signature=None, endpoint="payment-webhook.php", secret=SANDBOX_SECRET):
    body = json.dumps(payload, separators=(",", ":")).encode()
    timestamp = str(int(time.time()) if timestamp is None else timestamp)
    if signature is None:
        signature = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(BASE + "/" + endpoint, data=body, headers={
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
coupon = extract(buyer.load(catalog_a), "promotion")
assert "优惠券已领取" in buyer.submit(catalog_a, "claim_coupon", promotion=coupon)
assert "该优惠券已领取" in buyer.submit(catalog_a, "claim_coupon", promotion=coupon)
buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
buyer.submit(catalog_b, "cart", product=part_b, quantity=1)
second_device = Session()
second_device.login("buyer")
device_cart = second_device.load("/?page=cart")
assert "山形帆布托特包" in device_cart and "湖蓝陶瓷早餐杯" in device_cart
second_device.submit("/?page=cart", "cart", product=part_a, quantity=1,
                     revision=extract(device_cart, "revision"))
partial = buyer.submit("/?page=cart", "quote", channel="store", **pickup_choices(buyer.load("/?page=cart")))
assert "¥80.00" in partial
second_device.submit(catalog_a, "cart", product=part_a, quantity=1)
stale = buyer.submit("/?page=cart", "place", token=extract(partial, "token"))
assert "购物车内容已变化" in stale
bad = buyer.submit("/?page=cart", "quote", csrf="invalid", channel="store")
assert "表单已失效" in bad
quote = buyer.submit("/?page=cart", "quote", channel="store", coupon=coupon,
                     **pickup_choices(buyer.load("/?page=cart")))
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
assert "已使用" in buyer.load("/?page=account")
assert "生成提货码" not in paid and paid.count("待备货") == 2
for merchant, location_name in ((north, "北仓河畔店"), (pond, "池畔工房集市店")):
    merchant_page = merchant.load("/?page=merchant")
    assert location_name in merchant_page
    prepare_form, = forms(merchant_page, "pickup_prepare")
    merchant_order = extract(prepare_form, "merchant_order")
    location = extract(prepare_form, "pickup_location")
    scanner = merchant
    if merchant is north:
        scanner_address = f"scanner-{uuid4().hex[:8]}@example.test"
        merchant.submit("/?page=merchant", "shop_member", email=scanner_address, password="PickupFixture-2026",
                        position="pickup", pickup_location=location)
        scanner = Session()
        scanner.submit("/?page=account", "login", email=scanner_address, password="PickupFixture-2026")
        scanner_page = scanner.load("/?page=merchant")
        assert "备货完成" in scanner_page and "商品与库存" not in scanner_page and "本店账单" not in scanner_page
    prepared = scanner.submit("/?page=merchant", "pickup_prepare", merchant_order=merchant_order, pickup_location=location)
    assert "待提货" in prepared
    pass_page = buyer.submit("/?page=orders", "pickup_issue", merchant_order=merchant_order)
    pickup_code = re.search(r"提货码 <code>([A-F0-9]{16})</code>", pass_page)[1]
    assert 'class="pickup-barcode"' in pass_page and pass_page.count('class="bar') == 90
    scanner_page = scanner.load(f"/?page=merchant&pickup_order={merchant_order}")
    scanned_form, = forms(scanner_page, "pickup_redeem")
    scan_csrf = extract(scanned_form, "csrf")
    assert pickup_code not in buyer.load("/?page=orders") and pickup_code not in scanner_page
    fulfilled = scanner.submit("/?page=merchant", "pickup_redeem", merchant_order=merchant_order, csrf=scan_csrf,
                               pickup_location=location, code=pickup_code)
    assert "已履约" in fulfilled
    duplicate_scan = scanner.submit("/?page=merchant", "pickup_redeem", merchant_order=merchant_order, csrf=scan_csrf,
                                    pickup_location=location, code=pickup_code)
    assert "该提货码已核销" in duplicate_scan

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
print("Web flow passed: login, shared cart, checkout, signed payment, store pickup, repeat scan, refund, and settlement.")

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

category_name = f"服饰验收 {suffix}"
admin.submit("/?page=platform", "catalog_category", name=category_name)
category = re.search(rf'<option value="(\d+)">{re.escape(category_name)}</option>', owner.load("/?page=merchant"))
assert category, "new category missing from merchant editor"
sku = f"BAG-{suffix}-RED-M"
owner.submit("/?page=merchant", "sku_submit", product=0, revision=0, sku=sku, name="帆布包验收款",
             family="帆布包验收系列", category=category[1], color="红色", size="M", price="129.90", stock=3, pickup=1)
listing = f"/?q={sku}&category={category[1]}"
assert "当前没有符合条件的商品" in buyer.load(listing)
review = admin.load("/?page=platform")
assert sku in review and "红色 M" in review
admin.submit("/?page=platform", "sku_review", release=extract(review, "release"), decision="approve")
assert "当前没有符合条件的商品" in buyer.load(listing + "&channel=web")
store_listing = buyer.load(listing + "&channel=store")
assert "红色 · M" in store_listing and "¥129.90" in store_listing
variant_product = extract(store_listing, "product")
buyer.submit(listing, "cart", product=variant_product, quantity=1)
location_form, = forms(owner.load("/?page=merchant"), "pickup_location_save")
location_warehouse = re.search(r'<option value="(\d+)">主仓</option>', location_form)[1]
owner.submit("/?page=merchant", "pickup_location_save", pickup_location=0, warehouse=location_warehouse,
             code="STORE", name="商品验收自提点", address="演示园区 3 号", enabled=1)
variant_quote = buyer.submit("/?page=cart", "quote", channel="store", **pickup_choices(buyer.load("/?page=cart")))
owner.submit("/?page=merchant", "sku_unlist", product=variant_product)
blocked = buyer.submit("/?page=cart", "place", token=extract(variant_quote, "token"))
assert "商品已下架" in blocked
assert "当前没有符合条件的商品" in buyer.load(listing)
print("Catalog web flow passed: category, variant, review, channel filter, unlisting, and stale checkout rejection.")

if SHIPPING_SECRET:
    warehouse_name = "分仓验收仓"
    owner.submit("/?page=merchant", "warehouse_create", code="EAST", name=warehouse_name, address="测试仓库地址")
    delivery_sku = f"WEB-{suffix}"
    owner.submit("/?page=merchant", "sku_submit", product=0, revision=0, sku=delivery_sku, name="可配送验收款",
                 family="配送验收系列", category=category[1], price="12.00", stock=1, delivery=1)
    admin.submit("/?page=platform", "sku_review", release=extract(admin.load("/?page=platform"), "release"), decision="approve")
    delivery_listing = f"/?q={delivery_sku}&channel=web"
    delivery_product = extract(buyer.load(delivery_listing), "product")
    stock_form, = (form for form in forms(owner.load("/?page=merchant"), "sku_stock")
                   if warehouse_name in form and extract(form, "product") == delivery_product)
    owner.submit("/?page=merchant", "sku_stock", product=delivery_product, stock=2, csrf=extract(stock_form, "csrf"),
                 version=extract(stock_form, "version"), warehouse=extract(stock_form, "warehouse"))
    buyer.submit("/?page=cart", "cart", product=variant_product, remove=1)
    buyer.submit(delivery_listing, "cart", product=delivery_product, quantity=3)
    split = buyer.submit("/?page=cart", "quote", channel="web", destination="网页配送验收地址")
    assert "<dt>发货仓数</dt><dd>2</dd>" in split and "¥52.00" in split
    open_order = buyer.submit("/?page=cart", "place", token=extract(split, "token"))
    buyer.submit("/?page=orders", "pay", order=extract(open_order, "order"), amount=5200)
    ready = owner.load("/?page=merchant")
    parcel_forms = forms(ready, "shipment_dispatch")
    assert len(parcel_forms) == 2

    def dispatch(form, tracking):
        quantity_name, quantity = re.search(r'name="(quantities\[\d+\])"[^>]*value="(\d+)"', form).groups()
        return owner.submit("/?page=merchant", "shipment_dispatch", csrf=extract(form, "csrf"),
                            merchant_order=extract(form, "merchant_order"), warehouse=extract(form, "warehouse"),
                            key=extract(form, "key"), carrier="demo", tracking=tracking, **{quantity_name: quantity})

    tracking_a, tracking_b = f"WEB-A-{suffix}", f"WEB-B-{suffix}"
    partial = dispatch(parcel_forms[0], tracking_a)
    assert tracking_a in partial and "部分发货" in partial
    shipped = dispatch(parcel_forms[1], tracking_b)
    assert tracking_b in shipped and "待签收" in shipped
    event_b = {"carrier": "demo", "tracking": tracking_b, "event_reference": f"event-b-{suffix}"}
    if SANDBOX_SECRET and SANDBOX_SECRET != SHIPPING_SECRET:
        assert webhook(event_b, endpoint="shipping-webhook.php") == (401, {"error": "invalid_signature"})
    signed_b = webhook(event_b, endpoint="shipping-webhook.php", secret=SHIPPING_SECRET)
    assert signed_b[0] == 200 and signed_b[1]["status"] == "delivered"
    assert webhook(event_b, endpoint="shipping-webhook.php", secret=SHIPPING_SECRET) == signed_b
    assert "待签收" in owner.load("/?page=merchant")
    event_a = {"carrier": "demo", "tracking": tracking_a, "event_reference": f"event-a-{suffix}"}
    assert webhook(event_a, endpoint="shipping-webhook.php", secret=SHIPPING_SECRET)[0] == 200
    delivered = buyer.load("/?page=orders").split("</article>", 1)[0]
    assert tracking_a in delivered and tracking_b in delivered and delivered.count("已签收") == 2 and "已履约" in delivered
    assert "¥52.00" in owner.load("/?page=merchant").split('id="finance"', 1)[1]
    print("Delivery web flow passed: split freight, warehouse stock, parcel dispatch, signed delivery, and sale ledger.")
