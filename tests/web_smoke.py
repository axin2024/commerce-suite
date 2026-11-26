"""Exercise the web flow against a freshly seeded local demo."""

import csv
import hashlib
import hmac
import http.client
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from uuid import uuid4

from web_support import BASE, Session, extract, forms, pickup_choices


SANDBOX_SECRET = os.environ.get("COMMERCE_SANDBOX_WEBHOOK_SECRET")
SHIPPING_SECRET = os.environ.get("COMMERCE_SANDBOX_SHIPPING_SECRET")
REFUND_SECRET = os.environ.get("COMMERCE_SANDBOX_REFUND_SECRET")


def webhook(payload, timestamp=None, signature=None, endpoint="payment-webhook.php", secret=SANDBOX_SECRET,
            abandon_response=False):
    body = json.dumps(payload, separators=(",", ":")).encode()
    timestamp = str(int(time.time()) if timestamp is None else timestamp)
    if signature is None:
        signature = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    headers = {
        "Content-Type": "application/json",
        "X-Commerce-Timestamp": timestamp,
        "X-Commerce-Signature": signature,
    }
    if abandon_response:
        url = urllib.parse.urlsplit(BASE + "/" + endpoint)
        connection_class = http.client.HTTPSConnection if url.scheme == "https" else http.client.HTTPConnection
        connection = connection_class(url.hostname, url.port, timeout=8)
        try:
            connection.request("POST", url.path, body=body, headers=headers)
        finally:
            connection.close()
        return
    request = urllib.request.Request(BASE + "/" + endpoint, data=body, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=8) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        with error:
            return error.code, json.load(error)


with urllib.request.urlopen(BASE + "/health.php", timeout=8) as response:
    assert response.status == 200 and json.load(response) == {"status": "ready"}
    assert response.headers.get("Cache-Control") == "no-store"
    assert response.headers.get("Set-Cookie") is None

buyer, north, pond, finance, admin, payout = (Session() for _ in range(6))
for session, role in zip((buyer, north, pond, finance, admin, payout), ("buyer", "north", "pond", "finance", "admin", "payout")):
    session.login(role)
assert "审计记录" in admin.load("/?page=platform")
procurement = "/?page=procurement"
corp_key = uuid4().hex[:10]
assert "采购法人已登记" in admin.submit(procurement, "procurement_company", code=corp_key,
                               billing_name="集团-" + corp_key, email="buyer@example.test")
company_form = forms(buyer.load(procurement), "procurement_store")[-1]
store_page = buyer.submit(procurement, "procurement_store", company=extract(company_form, "company"),
                          code="HQ", name="东店-" + corp_key, center="运营", address="示例路5号")
assert "东店-" + corp_key in store_page
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
company_id = extract(company_form, "company")
store_id = extract(forms(store_page, "procurement_grant")[-1], "store")
supplier_id = re.search(r'<option value="(\d+)">北仓选物（未签约）', store_page)[1]
authorized = buyer.submit(procurement, "procurement_supplier", company=company_id, shop=supplier_id, enabled=1)
assert "北仓选物（已签约）" in authorized
pricing_fields = {"company": company_id, "product": part_a, "revision": 0, "from": "2020-01-01", "until": "2099-12-31"}
conflict = north.submit(procurement, "procurement_pricing", **pricing_fields, tiers="1,100,0,1000\n100,200,0,900")
assert "1–100 与 100–200" in conflict
published = north.submit(procurement, "procurement_pricing", **pricing_fields, tiers="1,99,0,1000\n100,200,0,900")
assert "企业协议价已发布" in published
priced = buyer.load(f"{procurement}&store={store_id}&product={part_a}&quantity=100&date=2027-01-01")
assert "协议版 1 · 数量 100 · 单价 ¥9.00 · 总价 ¥900.00" in priced
store_url = f"{procurement}&store={store_id}"
closes = (datetime.now(timezone.utc) + timedelta(seconds=20)).replace(microsecond=0)
tender_page = buyer.submit(store_url, "procurement_tender", store=store_id, subject="联合采购",
                           deadline=closes.strftime("%Y-%m-%dT%H:%M:%S"), lines="一批,60\n二批,40", **{"shops[]": supplier_id})
assert "已发送" in tender_page and "密封中" in tender_page
invite, = forms(north.load(procurement), "procurement_bid")
assert "一批" in invite and "二批" in invite
submitted = north.submit(procurement, "procurement_bid", rfq=extract(invite, "rfq"), revision=0, freight=0,
                         **{"products[1]": part_a, "products[2]": part_a})
assert "已记录" in submitted and "本店当前报价版 1" in submitted
assert "查看报价明细" not in buyer.load(store_url)
assert "法人授信额度已保存" in finance.submit(procurement, "procurement_limit", company=company_id, credit=100000)
assert "月度预算已保存" in buyer.submit(store_url, "procurement_budget", store=store_id,
                                    period=datetime.now(timezone.utc).strftime("%Y-%m"), limit=100000, threshold=100000)
print("Procurement web flow passed: supplier authorization, rejected overlap, published rates and store quote.")
guest_cart = Session()
guest_a = guest_cart.submit(catalog_a, "cart", product=part_a, quantity=2)
assert "山形帆布托特包" in guest_a and "登录或注册买家账号" in guest_a and not forms(guest_a, "quote")
guest_cart.submit(catalog_b, "cart", product=part_b, quantity=1)
assert "购物车为空" in Session().load("/?page=cart")
assert "购物车为空" in buyer.load("/?page=cart")
buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
guest_cart.login("buyer")
merged = guest_cart.load("/?page=cart")
assert "湖蓝陶瓷早餐杯" in merged and len(forms(merged, "quote")) == 1
for line in forms(merged, "cart"):
    assert 'name="quantity" value="1"' in line
assert len(forms(merged, "cart")) == 2
assert len(forms(buyer.load("/?page=cart"), "cart")) == 2
print("Guest cart web flow passed: anonymous isolation, account merge, and existing quantity preservation.")
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
    webhook(payload, abandon_response=True)
    recorded = f"{payload['reference']} · 已入账"
    deadline = time.monotonic() + 8
    while recorded not in finance.load("/?page=platform"):
        assert time.monotonic() < deadline, "capture was not recorded before retry"
        time.sleep(0.1)
    status, accepted = webhook(payload)
    assert status == 200 and accepted["status"] == "accepted" and accepted["payment_id"]
    assert webhook(payload) == (200, accepted)
    receipt_page = finance.load(f"/?page=platform&receipt={accepted['receipt_id']}")
    assert receipt_page.count(recorded) == 1 and f"订单 #{order_id} ·" in receipt_page
    print("Payment recovery web flow passed: abandoned response, persisted receipt, and two retries.")
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
assert "全单退完返还未过期券" in buyer.load("/?page=account")
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
review_form, = forms(north.load("/?page=merchant"), "return_approve")
refund_id = extract(review_form, "refund")
return_warehouse = re.search(r'<option value="(\d+)">主仓', review_form)[1]
address = "北仓园区 8 号退货窗口"
assert "待寄回" in north.submit("/?page=merchant", "return_approve", refund=refund_id, warehouse=return_warehouse,
                                address=address, note="请标明订单号")
assert address in buyer.load("/?page=orders")
assert "待验收" in buyer.submit("/?page=orders", "return_dispatch", refund=refund_id, carrier="demo",
                               tracking=f"return-{uuid4().hex}")
assert "待渠道退款" in north.submit("/?page=merchant", "return_inspect", refund=refund_id, accepted=1,
                                   restocked=1, note="包装完好")
assert "退款冲正" not in finance.load("/?page=platform")
if REFUND_SECRET:
    queue = finance.load("/?page=platform")
    token = re.search(r'<code class="return-token">([a-f0-9]{32})</code>', queue)[1]
    payment = re.search(r'<code class="return-payment">([^<]+)</code>', queue)[1]
    assert token not in admin.load("/?page=platform") and token not in buyer.load("/?page=orders")
    refund_payload = {"refund_token": token, "reference": f"returned-{uuid4().hex}", "payment_reference": payment,
                      "amount": 10800, "currency": "CNY"}

    def return_webhook(payload, **kwargs):
        return webhook(payload, endpoint="refund-webhook.php", secret=REFUND_SECRET, **kwargs)

    assert return_webhook(refund_payload, signature="0" * 64) == (401, {"error": "invalid_signature"})
    assert return_webhook(refund_payload, timestamp=int(time.time()) - 600) == (401, {"error": "invalid_signature"})
    assert return_webhook({**refund_payload, "amount": 10801}) == (409, {"error": "refund_conflict"})
    assert return_webhook({**refund_payload, "currency": "USD"}) == (409, {"error": "refund_conflict"})
    expected_refund = (200, {"refund_id": int(refund_id), "status": "refunded"})
    assert return_webhook(refund_payload) == return_webhook(refund_payload) == expected_refund
    assert refund_payload["reference"] in buyer.load("/?page=orders")
    assert "优惠券已返还" not in buyer.load("/?page=orders") and "已使用" in buyer.load("/?page=account")
    settlement = finance.load("/?page=platform")
    assert "退款冲正" in settlement and "¥64.80" in settlement and "−¥10.80" in settlement
    month = datetime.now(timezone.utc).strftime("%Y-%m")
    north_shop = re.search(r'page=platform&amp;shop=(\d+)#ledger">北仓选物</a>', settlement)[1]
    pond_shop = re.search(r'page=platform&amp;shop=(\d+)#ledger">池畔工房</a>', settlement)[1]
    preview_page = f"/?page=settlements&shop={pond_shop}&period={month}"
    preview = finance.load(preview_page)
    assert "结算预览" in preview and "¥72.00" in preview and "¥7.20" in preview and "¥64.80" in preview
    freeze_form, = forms(preview, "settlement_freeze")
    frozen = finance.submit(preview_page, "settlement_freeze", shop=pond_shop, period=month, currency="CNY",
                            preview_hash=extract(freeze_form, "preview_hash"))
    assert "待复核" in frozen and "冻结分录" in frozen
    batch = extract(frozen, "batch")
    detail_path = f"/?page=settlements&batch={batch}"
    csv_path = f"{detail_path}&download=csv"

    def assert_forbidden(actor, path):
        try:
            actor.load(path)
        except urllib.error.HTTPError as error:
            assert error.code == 403
        else:
            raise AssertionError("unexpected access to " + path)

    assert_forbidden(payout, detail_path)
    assert_forbidden(north, detail_path)
    assert_forbidden(north, csv_path)
    assert_forbidden(payout, csv_path)
    assert "复核金额不符" in finance.submit(detail_path, "settlement_review", batch=batch, verified_amount=6481)
    assert "待打款" in finance.submit(detail_path, "settlement_review", batch=batch, verified_amount=6480)
    payout_view = payout.load(detail_path)
    assert "待打款" in payout_view and "下载 CSV" not in payout_view and "冻结分录" not in payout_view
    bank_reference = f"bank-{uuid4().hex}"
    paid_settlement = payout.submit(detail_path, "settlement_transfer", batch=batch, reference=bank_reference,
                                    transfer_amount=6480)
    assert "已登记打款" in paid_settlement and bank_reference in paid_settlement
    assert bank_reference in pond.load(detail_path)
    csv_rows = list(csv.DictReader(pond.load(csv_path).splitlines()))
    row, = csv_rows
    assert row["shop_id"] == pond_shop and row["period_utc"] == month and row["currency"] == "CNY"
    assert (row["gross_minor"], row["commission_minor"], row["net_minor"]) == ("7200", "720", "6480")

    north_preview_path = f"/?page=settlements&shop={north_shop}&period={month}"
    north_freeze, = forms(finance.load(north_preview_path), "settlement_freeze")
    north_frozen = finance.submit(north_preview_path, "settlement_freeze", shop=north_shop, period=month, currency="CNY",
                                  preview_hash=extract(north_freeze, "preview_hash"))
    north_batch = extract(north_frozen, "batch")
    north_path = f"/?page=settlements&batch={north_batch}"
    north_csv = f"{north_path}&download=csv"
    assert "零额结清" in finance.submit(north_path, "settlement_review", batch=north_batch, verified_amount=0)
    north_rows = list(csv.DictReader(north.load(north_csv).splitlines()))
    assert all(row["shop_id"] == north_shop for row in north_rows)
    assert [(row["kind"], row["gross_minor"], row["commission_minor"], row["net_minor"]) for row in north_rows] == [
        ("sale", "10800", "1080", "9720"), ("refund", "-10800", "-1080", "-9720"),
    ]
    for merchant, allowed_batch, denied_batch, denied_shop in (
        (north, north_batch, batch, pond_shop), (pond, batch, north_batch, north_shop),
    ):
        merchant_list = merchant.load("/?page=settlements")
        assert f"&amp;batch={allowed_batch}\"" in merchant_list and f"&amp;batch={denied_batch}\"" not in merchant_list
        for other_path in (f"/?page=settlements&shop={denied_shop}",
                           f"/?page=settlements&batch={denied_batch}",
                           f"/?page=settlements&batch={denied_batch}&download=csv"):
            assert_forbidden(merchant, other_path)
    print("Return refund web flow passed: review, parcel tracking, inspection, signed receipt, and settlement.")
    print("Settlement web flow passed: monthly preview, finance review, cashier record, role scope, and CSV export.")
    if SANDBOX_SECRET:
        statement = "\n".join((
            "kind,order_id,reference,currency,amount_minor",
            f"capture,{order_id},{payload['reference']},CNY,18000",
            f"return_refund,{order_id},{refund_payload['reference']},CNY,10800",
            f"capture,{order_id},missing-{uuid4().hex},CNY,18000",
            f"capture,{order_id},invalid-reference,CNY,not-a-number",
            f"capture,{order_id},missing-{uuid4().hex},CNY,18000",
        ))
        assert finance.load("/channel-bill-sample.csv").startswith("kind,order_id,reference,currency,amount_minor")
        assert "表单已失效" in finance.upload_bill(statement, csrf="invalid")[1]
        assert "CSV 表头" in finance.upload_bill("invalid-header")[1]
        query, queued_bill = finance.upload_bill(statement)
        assert "待核对 4" in queued_bill and "格式错误 1" in queued_bill
        assert finance.upload_bill(statement)[0] == query
        bill = urllib.parse.parse_qs(query)["bill"][0]
        bill_path = "/?" + query
        assert_forbidden(north, bill_path)
        assert_forbidden(payout, "/?page=channel_bills")
        merchant_query, _ = north.upload_bill(statement)
        assert_forbidden(pond, "/?" + merchant_query)
        test_dsn = os.environ.get("COMMERCE_DSN", "")
        if os.environ.get("APP_DEMO") == "1" and re.search(r"(?:^|[;:])dbname=[^;]+_test(?:;|$)", test_dsn):
            php = ["php", "-d", "extension=pdo_mysql"] if os.name == "nt" else ["php"]
            subprocess.run([*php, "bin/worker.php", "--once"], check=True, timeout=15)
            report = finance.load(bill_path)
            assert "已匹配 2" in report and "待补回执 2" in report and "格式错误 1" in report
            sources = re.findall(r'href="(/\?page=platform&amp;[^"]+)">本地回执 #\d+</a>', report)
            assert len(sources) == 2
            assert payload["reference"] in finance.load(sources[0].replace("&amp;", "&"))
            assert refund_payload["reference"] in finance.load(sources[1].replace("&amp;", "&"))
            merchant_report = north.load("/?" + merchant_query)
            assert "已核对 4" in merchant_report and "本地回执 #" not in merchant_report
            retry_form, = forms(report, "bill_retry_selection")
            assert re.findall(r'name="lines\[\]" value="(\d+)"', retry_form) == ["4", "6"]
            assert "请选择 1 至" in finance.submit(bill_path, "bill_retry_selection", bill=bill)
            selection = finance.submit(bill_path, "bill_retry_selection", bill=bill,
                                       **{"lines[0]": 4, "lines[1]": 5, "lines[2]": 7, "lines[3]": 4})
            assert "第 4 行 · 已重新排队" in selection and selection.count("第 4 行 ·") == 1
            assert "第 5 行 · 未重新排队（格式错误）" in selection and "第 7 行 · 当前任务中没有此记录" in selection
            assert "待核对 1" in selection and "待补回执 1" in selection
            assert "第 4 行 · 已重新排队" in finance.load(bill_path)
            replay = finance.submit(bill_path, "bill_retry", bill=bill)
            assert "重新排队 1 条记录" in replay and "待核对 2" in replay
            print("Channel bill web flow passed: upload, replay, receipt links, tenant scope, and selected and whole-batch retry.")
print("Web flow passed: login, shared cart, checkout, payment, store pickup, and repeat scan.")

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
assert not forms(blocked, "place")
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
    if REFUND_SECRET:
        claim_form, = forms(delivered, "refund")
        buyer.submit("/?page=orders", "refund", item=extract(claim_form, "item"), key=extract(claim_form, "key"),
                     quantity=1, reason="分仓退运费验收")
        approve_freight, = forms(owner.load("/?page=merchant"), "return_approve")
        freight_refund = extract(approve_freight, "refund")
        owner.submit("/?page=merchant", "return_approve", refund=freight_refund, warehouse=extract(stock_form, "warehouse"))
        finance_freight = f"/?page=platform&freight_return={freight_refund}"
        assert not forms(admin.load(finance_freight), "return_freight")
        merchant_freight, = forms(owner.load("/?page=merchant"), "return_freight")
        assert "商家承担退运费" in owner.load("/?page=merchant") and extract(merchant_freight, "refund") == freight_refund
        platform_freight, = forms(finance.load(finance_freight), "return_freight")
        assert extract(platform_freight, "refund") == freight_refund
        finance_saved = finance.submit(finance_freight, "return_freight", refund=freight_refund, freight="8.00", note="分仓配送延误")
        assert "退运费 ¥8.00 · 平台承担" in finance_saved
        assert "分仓配送延误" in owner.load("/?page=merchant")
        assert "退运费 ¥8.00 · 平台承担" in buyer.load("/?page=orders")
        buyer.submit("/?page=orders", "return_dispatch", refund=freight_refund, carrier="demo", tracking=f"web-return-{suffix}")
        owner.submit("/?page=merchant", "return_inspect", refund=freight_refund, accepted=1, restocked=0)
        freight_queue = finance.load(f"/?page=platform&return_receipt={freight_refund}")
        assert "货款 ¥12.00" in freight_queue
        freight_payload = {
            "refund_token": re.search(r'<code class="return-token">([a-f0-9]{32})</code>', freight_queue)[1],
            "payment_reference": re.search(r'<code class="return-payment">([^<]+)</code>', freight_queue)[1],
            "reference": f"freight-refunded-{suffix}", "amount": 2000, "currency": "CNY",
        }
        assert return_webhook({**freight_payload, "amount": 1200}) == (409, {"error": "refund_conflict"})
        assert return_webhook(freight_payload) == (200, {"refund_id": int(freight_refund), "status": "refunded"})
        assert freight_payload["reference"] in buyer.load("/?page=orders")
        merchant_ledger = owner.load("/?page=merchant").split('id="finance"', 1)[1]
        assert re.search("−¥20.00.*−¥1.20.*¥8.00.*−¥10.80", merchant_ledger, re.S)
        print("Refund freight web flow passed: finance decision, buyer disclosure, signed total, and merchant statement.")

pre_sku = f"PRE-{suffix}"
owner.submit("/?page=merchant", "sku_submit", product=0, revision=0, sku=pre_sku, name="预售验收款", family="预售验收系列",
             category=category[1], price="23.00", stock=0, pickup=1)
admin.submit("/?page=platform", "sku_review", release=extract(admin.load("/?page=platform"), "release"), decision="approve")
listing = f"/?q={pre_sku}&channel=store"
assert "可售 0" in buyer.load(listing) and "选购此批次" not in buyer.load(listing)
product_panel = owner.load("/?page=merchant").split(f" · {pre_sku} · ", 1)[1]
create_batch = forms(product_panel, "preorder_create")[0]
pre_product = extract(create_batch, "product")
pre_warehouse = re.search(r'<option value="(\d+)">主仓</option>', create_batch)[1]
now = datetime.now(timezone.utc)
cutoff = (now + timedelta(hours=3)).strftime("%Y-%m-%dT%H:%M")
expected = (now + timedelta(days=2)).strftime("%Y-%m-%d")
owner.submit("/?page=merchant", "preorder_create", product=pre_product, warehouse=pre_warehouse, code="SPRING",
             quota=2, cutoff=cutoff, expected=expected)
on_sale = buyer.load(listing)
assert "预售 SPRING" in on_sale and f"预计 {expected} 到货" in on_sale
batch = extract(on_sale, "preorder_batch")
assert "可在商品验收自提点自提" in on_sale
if not SHIPPING_SECRET:
    buyer.submit("/?page=cart", "cart", product=variant_product, remove=1)
put_in_cart = buyer.submit(listing, "cart", product=pre_product, preorder_batch=batch, quantity=1)
assert "预售 SPRING" in put_in_cart
pre_quote = buyer.submit("/?page=cart", "quote", channel="store", **pickup_choices(put_in_cart))
assert "预售 SPRING" in pre_quote and "¥23.00" in pre_quote
created = buyer.submit("/?page=cart", "place", token=extract(pre_quote, "token"))
buyer.submit("/?page=orders", "pay", order=extract(created, "order"), amount=2300)
awaiting = owner.load("/?page=merchant")
assert "等待预售到货" in awaiting and not forms(awaiting, "pickup_prepare")
closed = owner.submit("/?page=merchant", "preorder_close", batch=batch)
assert "已截单" in closed and "选购此批次" not in buyer.load(listing)
stocked = owner.submit("/?page=merchant", "preorder_receive", batch=batch, units=2)
assert "已入库" in stocked and "实收到货 2 件" in stocked
assert "可售 1" in buyer.load(listing) and "选购此批次" not in buyer.load(listing)
prepare_form, = forms(stocked, "pickup_prepare")
pre_child = extract(prepare_form, "merchant_order")
pre_location = extract(prepare_form, "pickup_location")
owner.submit("/?page=merchant", "pickup_prepare", merchant_order=pre_child, pickup_location=pre_location)
ready = buyer.submit("/?page=orders", "pickup_issue", merchant_order=pre_child)
pickup_code = re.search(r"提货码 <code>([A-F0-9]{16})</code>", ready)[1]
assert "预售 SPRING" in ready and "已到货" in ready
assert "已完成提货核销" in owner.submit("/?page=merchant", "pickup_redeem", merchant_order=pre_child,
                                   pickup_location=pre_location, code=pickup_code)
print("Preorder web flow passed: publish, cart, payment, cutoff, warehouse receipt, and store pickup.")

if REFUND_SECRET:
    revised_campaign = admin.submit("/?page=platform", "promotion_publish", code="marketplace-opening",
                                    name="跨店券新版验收", threshold="200.00", discount="19.00",
                                    requires_claim=1, stack_member_price=1)
    assert "跨店券新版验收" in revised_campaign
    article = buyer.load("/?page=orders").split(f"<h2>订单 #{order_id} ", 1)[1].split("</article>", 1)[0]
    last_refund_form, = forms(article, "refund")
    buyer.submit("/?page=orders", "refund", item=extract(last_refund_form, "item"), key=extract(last_refund_form, "key"),
                 quantity=1, reason="完整退回跨店订单")
    approve_last, = forms(pond.load("/?page=merchant"), "return_approve")
    last_refund = extract(approve_last, "refund")
    pond_main = re.search(r'<option value="(\d+)">主仓', approve_last)[1]
    pond.submit("/?page=merchant", "return_approve", refund=last_refund, warehouse=pond_main, address="池畔退货窗口")
    buyer.submit("/?page=orders", "return_dispatch", refund=last_refund, carrier="demo", tracking=f"coupon-return-{suffix}")
    pond.submit("/?page=merchant", "return_inspect", refund=last_refund, accepted=1, restocked=1)
    coupon_receipts = finance.load(f"/?page=platform&return_receipt={last_refund}")
    last_payload = {
        "refund_token": re.search(r'<code class="return-token">([a-f0-9]{32})</code>', coupon_receipts)[1],
        "payment_reference": re.search(r'<code class="return-payment">([^<]+)</code>', coupon_receipts)[1],
        "reference": f"coupon-restored-{suffix}", "amount": 7200, "currency": "CNY",
    }
    expected_last = (200, {"refund_id": int(last_refund), "status": "refunded"})
    assert return_webhook(last_payload) == return_webhook(last_payload) == expected_last
    assert buyer.load("/?page=orders").count("优惠券已返还") == 1
    account = buyer.load("/?page=account")
    assert "跨店满 200 减 20" in account and ">可用</span>" in account and "跨店券新版验收" not in account
    print("Coupon refund web flow passed: partial refund hold, revised campaign, final receipt, and original coupon restored.")

    assert "无权执行" in finance.submit("/?page=account", "loyalty_publish", earn=2, redeem=20, return_spent=1)
    published = admin.submit("/?page=platform", "loyalty_publish", earn=2, redeem=20, return_spent=1)
    assert "每元赠 2 积分" in published and "最高抵扣 20%" in published

    def north_points_quote(points):
        buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
        return buyer.submit("/?page=cart", "quote", channel="store", points=points,
                            **pickup_choices(buyer.load("/?page=cart")))

    earn_quote = north_points_quote(0)
    assert "支付后预计赠 240 积分" in earn_quote
    earn_order = buyer.submit("/?page=cart", "place", token=extract(earn_quote, "token"))
    buyer.submit("/?page=orders", "pay", order=extract(earn_order, "order"), amount=12000)
    assert "可用积分 <strong>240</strong>" in buyer.load("/?page=account")
    prior_children = {extract(form, "merchant_order") for form in forms(north.load("/?page=merchant"), "pickup_prepare")}

    redeem_quote = north_points_quote(100)
    assert "积分抵扣</dt><dd>−¥1.00" in redeem_quote and "¥119.00" in redeem_quote
    redeem_order = buyer.submit("/?page=cart", "place", token=extract(redeem_quote, "token"))
    redeem_parent = extract(redeem_order, "order")
    held = buyer.load("/?page=account")
    assert "可用积分 <strong>140</strong>" in held and "待付款预占 100" in held
    buyer.submit("/?page=orders", "pay", order=redeem_parent, amount=11900)
    assert "可用积分 <strong>378</strong>" in buyer.load("/?page=account")
    ready_pickup, = (form for form in forms(north.load("/?page=merchant"), "pickup_prepare")
                     if extract(form, "merchant_order") not in prior_children)
    points_child = extract(ready_pickup, "merchant_order")
    points_location = extract(ready_pickup, "pickup_location")
    north.submit("/?page=merchant", "pickup_prepare", merchant_order=points_child, pickup_location=points_location)
    pass_page = buyer.submit("/?page=orders", "pickup_issue", merchant_order=points_child)
    pass_code = re.search(r"提货码 <code>([A-F0-9]{16})</code>", pass_page)[1]
    north.submit("/?page=merchant", "pickup_redeem", merchant_order=points_child, pickup_location=points_location,
                 code=pass_code)
    points_article = buyer.load("/?page=orders").split(f"<h2>订单 #{redeem_parent} ", 1)[1].split("</article>", 1)[0]
    points_claim_form, = forms(points_article, "refund")
    buyer.submit("/?page=orders", "refund", item=extract(points_claim_form, "item"), key=extract(points_claim_form, "key"),
                 quantity=1, reason="积分现金混合退款验收")
    points_approval, = forms(north.load("/?page=merchant"), "return_approve")
    points_claim = extract(points_approval, "refund")
    north.submit("/?page=merchant", "return_approve", refund=points_claim, warehouse=return_warehouse, address=address)
    buyer.submit("/?page=orders", "return_dispatch", refund=points_claim, carrier="demo", tracking=f"points-{suffix}")
    admin.submit("/?page=platform", "loyalty_publish", earn=0, redeem=0)
    north.submit("/?page=merchant", "return_inspect", refund=points_claim, accepted=1, restocked=1)
    point_queue = finance.load(f"/?page=platform&return_receipt={points_claim}")
    assert "返还抵扣 100 积分 · 扣回赠送 238 积分" in point_queue
    point_payload = {
        "refund_token": re.search(r'<code class="return-token">([a-f0-9]{32})</code>', point_queue)[1],
        "payment_reference": re.search(r'<code class="return-payment">([^<]+)</code>', point_queue)[1],
        "reference": f"loyalty-refund-{suffix}", "amount": 11900, "currency": "CNY",
    }
    assert return_webhook({**point_payload, "amount": 12000}) == (409, {"error": "refund_conflict"})
    expected_points_refund = (200, {"refund_id": int(points_claim), "status": "refunded"})
    assert return_webhook(point_payload) == return_webhook(point_payload) == expected_points_refund
    point_account = buyer.load("/?page=account")
    assert "可用积分 <strong>240</strong>" in point_account
    assert "退款返还抵扣" in point_account and "退款扣回赠送" in point_account
    print("Loyalty web flow passed: published policy, wallet hold, mixed payment, versioned refund, and account history.")

freight_buyer = Session()
freight_buyer.submit("/?page=account", "register",
                     email=f"freight-{uuid4().hex}@example.test", password="CommerceFixture-2026")
freight_buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
old_freight = freight_buyer.submit("/?page=cart", "quote", channel="web", destination="运费规则测试地址")
assert "运费</dt><dd>¥8.00" in old_freight

merchant_path = "/?page=merchant"
freight_form, = forms(north.load(merchant_path), "freight_publish")
initial_revision = extract(freight_form, "revision")
published = north.submit(merchant_path, "freight_publish", revision=initial_revision, strategy="per_order_v1",
                         base_fee="6.00", free_threshold="121.00")
freight_form, = forms(published, "freight_publish")
paid_freight_revision = extract(freight_form, "revision")
assert paid_freight_revision != initial_revision and "¥121.00" in published
assert "运费规则已更新" in north.submit(merchant_path, "freight_publish", revision=initial_revision,
                                        strategy="per_order_v1", base_fee="8.00")
stale = freight_buyer.submit("/?page=cart", "place", token=extract(old_freight, "token"))
assert "运费、积分规则或分仓结果已变化" in stale
assert "报价变更" in stale and "确认新报价并下单" in stale
assert "¥8.00</td><td>¥6.00" in stale and "¥128.00</td><td>¥126.00" in stale
replacement_token = extract(stale, "token")
assert replacement_token != extract(old_freight, "token")
assert not forms(freight_buyer.load("/?page=orders"), "cancel")
placed_freight = freight_buyer.submit("/?page=cart", "place", token=replacement_token)
freight_parent = extract(placed_freight, "order")
assert "¥126.00" in placed_freight
same_freight_order = freight_buyer.submit("/?page=orders", "place", token=replacement_token)
assert extract(same_freight_order, "order") == freight_parent
published = north.submit(merchant_path, "freight_publish", revision=paid_freight_revision,
                         strategy="per_order_v1", base_fee="6.00", free_threshold="120.00")
freight_form, = forms(published, "freight_publish")
free_freight_revision = extract(freight_form, "revision")
freight_buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
free_freight = freight_buyer.submit("/?page=cart", "quote", channel="web", destination="运费规则测试地址")
assert "运费</dt><dd>¥0.00" in free_freight
order_row = re.search(rf"<tr><th scope=\"row\">#\d+</th><td>#{freight_parent}</td>.*?</tr>",
                      north.load("/?page=merchant_orders"), re.S)[0]
assert "¥6.00" in order_row and f"<td>#{paid_freight_revision}</td>" in order_row
if 'value="consolidation_v1"' in freight_form:
    plugin_published = north.submit(merchant_path, "freight_publish", revision=free_freight_revision,
                                    strategy="consolidation_v1", base_fee="7.01")
    plugin_form, = forms(plugin_published, "freight_publish")
    plugin_revision = extract(plugin_form, "revision")
    plugin_freight = freight_buyer.submit("/?page=cart", "quote", channel="web", destination="扩展计费验收地址")
    assert "运费</dt><dd>¥7.01" in plugin_freight
    plugin_order = freight_buyer.submit("/?page=cart", "place", token=extract(plugin_freight, "token"))
    plugin_parent = extract(plugin_order, "order")
    plugin_row = re.search(rf"<tr><th scope=\"row\">#\d+</th><td>#{plugin_parent}</td>.*?</tr>",
                           north.load("/?page=merchant_orders"), re.S)[0]
    assert "¥7.01" in plugin_row and f"<td>#{plugin_revision}</td>" in plugin_row
print("Freight policy web flow passed: owner publishing, stale quote, free shipping, and frozen order freight.")

strategy_buyer = Session()
strategy_buyer.submit("/?page=account", "register", email=f"promotion-{uuid4().hex}@example.test",
                      password="CommerceFixture-2026")
promotion_page = "/?page=platform"
strategy_sale = {
    "code": "web-percent", "name": "比例券验收", "threshold": "200.00", "discount": "30.00",
    "requires_claim": "1", "stack_member_price": "1", "discount_strategy": "percentage_v1", "rate_percent": "12.50",
}
assert "12.5%" in admin.submit(promotion_page, "promotion_publish", **strategy_sale)
offer = re.search(r"<strong>比例券验收</strong>.*?</form>", strategy_buyer.load(catalog_a), re.S)[0]
assert "最高减 ¥30.00" in offer and "12.5%" in offer
coupon_id = extract(offer, "promotion")
assert "优惠券已领取" in strategy_buyer.submit(catalog_a, "claim_coupon", promotion=coupon_id)
strategy_buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
cart = strategy_buyer.load("/?page=cart")
too_small = strategy_buyer.submit("/?page=cart", "quote", channel="store", coupon=coupon_id, **pickup_choices(cart))
assert "当前商品未达到活动门槛" in too_small
strategy_buyer.submit(catalog_b, "cart", product=part_b, quantity=1)
cart = strategy_buyer.load("/?page=cart")
discounted = strategy_buyer.submit("/?page=cart", "quote", channel="store", coupon=coupon_id, **pickup_choices(cart))
assert "−¥25.00" in discounted and "¥175.00" in discounted
admin.submit(promotion_page, "promotion_publish", **{**strategy_sale, "discount": "10.00", "rate_percent": "5.00"})
old_coupon_terms = strategy_buyer.load("/?page=account")
assert "12.5%" in old_coupon_terms and "¥30.00" in old_coupon_terms
placed = strategy_buyer.submit("/?page=cart", "place", token=extract(discounted, "token"))
assert "优惠 ¥25.00" in placed and "¥175.00" in placed

if 'value="threshold_steps_v1"' in admin.load(promotion_page):
    tiered = {**strategy_sale, "code": "web-steps", "name": "阶梯优惠验收", "threshold": "100.00", "discount": "22.00",
              "discount_strategy": "threshold_steps_v1", "requires_claim": "0"}
    assert "按满额档位累计优惠" in admin.submit(promotion_page, "promotion_publish", **tiered)
    strategy_buyer.submit(catalog_a, "cart", product=part_a, quantity=1)
    strategy_buyer.submit(catalog_b, "cart", product=part_b, quantity=1)
    cart = strategy_buyer.load("/?page=cart")
    step_quote = strategy_buyer.submit("/?page=cart", "quote", channel="store", **pickup_choices(cart))
    assert "阶梯优惠验收" in step_quote and "−¥22.00" in step_quote and "¥178.00" in step_quote
    admin.submit(promotion_page, "promotion_publish", **tiered)
    old_step_quote = strategy_buyer.submit("/?page=cart", "place", token=extract(step_quote, "token"))
    assert "请核对新报价后重新确认" in old_step_quote
    assert extract(old_step_quote, "token") != extract(step_quote, "token")
    print("Tiered promotion extension passed: capped checkout and renewed quote confirmation.")
print("Promotion strategy web flow passed: publishing, scoped checkout, pinned coupon terms, and rule changes.")

time.sleep(max(0, closes.timestamp() - time.time() + 1))
opened = buyer.load(store_url)
award_form, = forms(opened, "procurement_award")
assert "¥900.00" in opened and "已开标" in opened
api_payload = {"rfq_id": int(extract(award_form, "rfq")), "bid_revision": int(extract(award_form, "bid")),
               "ship_to": int(store_id), "accepted_lines": [{"line": 1, "qty": 60}, {"line": 2, "qty": 40}]}
award_key = uuid4().hex

def award_request(body):
    req = urllib.request.Request(BASE + "/procurement/awards",data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json","X-CSRF-Token": extract(award_form, "csrf"),
                                          "Idempotency-Key": award_key})
    try:
        with buyer.client.open(req,timeout=8) as response:
            return response.status,json.load(response)
    except urllib.error.HTTPError as error:
        with error:
            return error.code,json.load(error)

status, result = award_request(api_payload)
assert status == 200 and result["order_id"] and int(result["credit_reserved_minor"]) == 90000, (status,result)
assert award_request(api_payload) == (200,result)
assert award_request({**api_payload, "accepted_lines": [{"line": 1,"qty": 10}]})[0] == 409
assert award_request({**api_payload, "ship_to": 0})[1]["field"] == "ship_to"
assert "¥100.00" in buyer.load(store_url)
receipt_fields = {"store": store_id,"order": result["order_id"],"line": 1,"delivery_ref": "DOCK-WEB",
                  "accepted": 60,"damaged": 0}
received = buyer.submit(store_url,"procurement_receive",**receipt_fields)
assert "签收回执 #" in received and "¥540.00" in received
replayed = buyer.submit(store_url,"procurement_receive",**receipt_fields)
assert replayed.count("送货单 DOCK-WEB") == 1
assert "收货超出剩余 0 件" in buyer.submit(store_url,"procurement_receive",**{**receipt_fields,"delivery_ref":"DOCK-EXTRA","accepted":1})
print("Procurement award HTTP flow passed: sealed opening, order and credit readback, replay and conflicting key.")

receipt_id = int(extract(forms(received,"procurement_return")[0],"receipt"))
current_month = datetime.now(timezone.utc).strftime("%Y-%m")
month_end = finance.submit(procurement,"procurement_close",company=company_id,month=current_month)
statement_id = int(re.search(r"法人账单 #(\d+) 已关账", month_end)[1])
def download(session, kind, document):
    body = session.load(f"{procurement}&download={kind}&document={document}")
    return list(csv.DictReader(body.splitlines()))

frozen = download(finance,"statement",statement_id)
assert len(frozen) == 1 and int(frozen[0]["amount_minor"]) == 54000
returned = buyer.submit(store_url,"procurement_return",store=store_id,receipt=receipt_id,reference="RETURN-WEB",quantity=10)
credit_id = int(re.search(r"退货贷项 #(\d+) 已入账",returned)[1])
credit_row, = download(finance,"credit",credit_id)
received_row, = download(buyer,"receipt",receipt_id)
for key in ("award_id","order_id","line_no","receipt_id"):
    assert frozen[0][key] == credit_row[key] == received_row[key]
assert credit_row["period"] > frozen[0]["period"] and int(credit_row["amount_minor"]) == -9000
assert frozen == download(finance,"statement",statement_id)
csv_text = f"bank_ref,value_date,amount_minor\nBANK-WEB,{current_month}-01,40000\n"
_, bank_page = finance.upload_bill(csv_text,company=company_id,csrf=extract(month_end,"csrf"))
bank_form, = forms(bank_page,"procurement_allocate")
applied = finance.submit(procurement,"procurement_allocate",bank=extract(bank_form,"bank"),company=company_id,
                         line=frozen[0]["billing_line_id"],amount=40000,key=extract(bank_form,"key"))
assert "核销回执 #" in applied and "待核销 ¥0.00" in applied
assert "待核销 ¥0.00" in finance.upload_bill(csv_text,company=company_id,csrf=extract(applied,"csrf"))[1]
print("Procurement month close and credit export passed:",json.dumps({"receipt":received_row,"statement":frozen[0],"credit":credit_row}))

receiving_user = Session()
receiver_email = f"warehouse-{uuid4().hex[:12]}@example.test"
receiving_user.submit("/?page=account","register",email=receiver_email,password="WarehouseFixture-2026")
buyer.submit(store_url,"procurement_grant",store=store_id,email=receiver_email,duty="receiver")
bookmark = f"{store_url}&order={result['order_id']}"
assert "登记到货" in receiving_user.load(bookmark) and "发起询价" not in receiving_user.load(bookmark)
buyer.submit(store_url,"procurement_revoke",store=store_id,email=receiver_email)
try:
    receiving_user.load(bookmark)
except urllib.error.HTTPError as error:
    assert error.code == 404
else:
    raise AssertionError("revoked receiver retained order access")
preview_page = north.load(f"{procurement}&preview_company={company_id}&preview_sku={part_a}&quantity=100&date=2027-01-01")
assert "100–200 件 · 单价 ¥9.00" in preview_page
