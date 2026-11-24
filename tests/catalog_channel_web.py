import json
import re
import urllib.error
import urllib.parse
import urllib.request

from web_support import BASE, Session, extract, forms


CHANNELS = "/?page=sales_channels"


def feed(token=None, method="GET", **query):
    url = BASE + "/catalog-feed.php?" + urllib.parse.urlencode(query)
    request = urllib.request.Request(url, method=method)
    if token is not None:
        request.add_header("Authorization", f"Bearer {token}")
    try:
        response = urllib.request.urlopen(request, timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        assert response.headers.get("Cache-Control") == "no-store"
        assert response.headers.get("Set-Cookie") is None
        if response.status == 401:
            assert response.headers.get("WWW-Authenticate") == "Bearer"
        return response.status, json.load(response)


def credential(html):
    return re.search(r'<code class="catalog-credential">(cf1_[a-f0-9]{64})</code>', html)[1]


def create(owner, label):
    page = owner.submit(CHANNELS, "catalog_channel_create", code="partner-shop", label=label)
    rotate, = forms(page, "catalog_channel_rotate")
    channel = extract(rotate, "channel")
    token = credential(page)
    assert token not in owner.load(f"{CHANNELS}&channel={channel}")
    return channel, token


def catalog(token, **query):
    status, body = feed(token, **query)
    assert status == 200, (status, body)
    assert (body["version"], body["channel"], body["currency"]) == (1, "partner-shop", "CNY")
    return body


def change(owner, channel, sku, enabled="1", revision=0):
    return owner.submit(f"{CHANNELS}&channel={channel}", "catalog_channel_listing",
                        channel=channel, sku=sku, enabled=enabled, revision=revision)


north, pond, buyer, admin = Session(), Session(), Session(), Session()
for session, role in ((north, "north"), (pond, "pond"), (buyer, "buyer"), (admin, "admin")):
    session.login(role)
north_id, north_key = create(north, "北仓合作商城")
pond_id, pond_key = create(pond, "池畔合作商城")
north_page = f"{CHANNELS}&channel={north_id}"
assert north_id != pond_id and north_key != pond_key
assert catalog(north_key)["items"] == []
assert "本店已存在这个渠道代码" in north.submit(CHANNELS, "catalog_channel_create",
                                             code="partner-shop", label="重复代码")
assert feed(access_token="ignored")[0] == 401
assert feed(north_key, method="POST")[0] == 405
assert feed(north_key, limit=101)[0] == 400
assert feed(north_key, **{"cursor[]": "bad"})[0] == 400
try:
    pond.load(north_page)
except urllib.error.HTTPError as error:
    assert error.code == 403
else:
    raise AssertionError("another shop opened channel management")
try:
    pond.submit(CHANNELS, "catalog_channel_listing", channel=north_id, sku="POND-01", enabled=1, revision=0)
except urllib.error.HTTPError as error:
    assert error.code == 403
else:
    raise AssertionError("another shop edited a channel")
north.submit("/?page=merchant", "shop_member", email="channel-staff@example.test",
             password="CatalogFixture-2026", position="catalog")
staff = Session()
staff.submit("/?page=account", "login", email="channel-staff@example.test", password="CatalogFixture-2026")
try:
    staff.submit("/?page=merchant", "catalog_channel_create", code="staff-shop", label="店员新建")
except urllib.error.HTTPError as error:
    assert error.code == 403
else:
    raise AssertionError("catalog staff issued a credential")
assert "请选择本店已审核" in change(north, north_id, "POND-01")
for sku in ("NORTH-01", "NORTH-02"):
    assert "操作已保存" in change(north, north_id, sku)
assert "操作已保存" in change(pond, pond_id, "POND-01")
assert [item["sku"] for item in catalog(pond_key)["items"]] == ["POND-01"]
first = catalog(north_key, limit=1)
start, = first["items"]
assert start["sku"] == "NORTH-01" and start["available_units"] == 36
assert start["product"]["price_minor"] == 12000 and start["status"] == "listed"
cursor = first["next_cursor"]
assert feed(pond_key, cursor=cursor)[0] == 400
assert feed(north_key, cursor=cursor + "0")[0] == 400

# Add a reviewed SKU after starting the scan and continue with the same cursor.
merchant = "/?page=merchant"
platform = "/?page=platform"
category = re.search(r'<option value="(\d+)">箱包</option>', north.load(merchant))[1]
new_sku = "CHANNEL-ONLY-01"
assert "操作已保存" in north.submit(merchant, "sku_submit", product=0, revision=0, sku=new_sku,
    name="渠道验收款", family="渠道验收系列", category=category, price="24.00", stock=6, delivery=1)
assert "请选择本店已审核" in change(north, north_id, new_sku)
pending, = forms(admin.load(platform), "sku_review")
admin.submit(platform, "sku_review", release=extract(pending, "release"), decision="approve")
assert "操作已保存" in change(north, north_id, new_sku)
second = catalog(north_key, limit=1, cursor=cursor)
assert [item["sku"] for item in second["items"]] == ["NORTH-02"] and second["next_cursor"] is None
assert [item["sku"] for item in catalog(north_key)["items"]] == ["NORTH-01", "NORTH-02", new_sku]
print("Catalog channel access and pagination passed.")

product_query = "/?q=NORTH-01"
product = extract(buyer.load(product_query), "product")
buyer.submit(product_query, "cart", product=product, quantity=1)
quote = buyer.submit("/?page=cart", "quote", channel="web", destination="渠道验收配送地址")
order = buyer.submit("/?page=cart", "place", token=extract(quote, "token"))
assert "已创建，请在 15 分钟内完成支付" in order
held = catalog(north_key)["items"][0]
assert held["available_units"] == 35 and held["inventory_revision"] > start["inventory_revision"]
buyer.submit("/?page=orders", "cancel", order=extract(order, "order"))
released = catalog(north_key)["items"][0]
assert released["available_units"] == 36 and released["inventory_revision"] > held["inventory_revision"]

sku_form = next(f for f in forms(north.load(merchant), "sku_submit") if extract(f, "product") == product)
north.submit(merchant, "sku_submit", product=product, revision=extract(sku_form, "revision"),
             sku="NORTH-01", name="渠道验收新名称", family="山形帆布包", category=category, price="121.00", stock=36, delivery=1)
assert catalog(north_key)["items"][0]["product"] == start["product"]
pending, = forms(admin.load(platform), "sku_review")
admin.submit(platform, "sku_review", release=extract(pending, "release"), decision="approve")
updated = catalog(north_key)["items"][0]
assert updated["product"]["price_minor"] == 12100 and updated["catalog_revision"] > start["catalog_revision"]
assert updated["listing_id"] == start["listing_id"]
print("Catalog publishes reviewed prices and transactional sellable inventory.")

assert "操作已保存" in change(north, north_id, "NORTH-01", "0", updated["listing_revision"])
withdrawn = catalog(north_key)["items"][0]
assert withdrawn["listing_id"] == start["listing_id"] and withdrawn["status"] == "withdrawn"
assert withdrawn["available_units"] == 0 and withdrawn["product"] is None
assert "渠道商品已变更" in change(north, north_id, "NORTH-01", revision=updated["listing_revision"])
assert "操作已保存" in change(north, north_id, "NORTH-01", revision=withdrawn["listing_revision"])
assert catalog(north_key)["items"][0]["status"] == "listed"
sku_archive = next(f for f in forms(north.load(merchant), "sku_unlist") if extract(f, "product") == product)
north.submit(merchant, "sku_unlist", product=extract(sku_archive, "product"))
assert catalog(north_key)["items"][0]["status"] == "withdrawn"

shop_forms = forms(admin.load(platform), "shop_status")
shops = [extract(f, "shop") for f in shop_forms if extract(f, "decision") == "suspend"]
for shop_id in shops:
    admin.submit(platform, "shop_status", shop=shop_id, decision="suspend", reason="渠道验收停业")
    paused = catalog(north_key)
    admin.submit(platform, "shop_status", shop=shop_id, decision="resume", reason="渠道验收恢复")
    if paused["shop_status"] == "suspended":
        assert all(item["status"] == "withdrawn" for item in paused["items"])
        break
else:
    raise AssertionError("shop suspension did not reach the feed")
assert catalog(north_key)["items"][1]["status"] == "listed"

rotate, = forms(north.load(north_page), "catalog_channel_rotate")
generation = extract(rotate, "generation")
new_page = north.submit(north_page, "catalog_channel_rotate", channel=north_id, generation=generation)
new_key = credential(new_page)
assert new_key != north_key and new_key not in north.load(north_page)
assert feed(north_key)[0] == 401 and feed(new_key, cursor=cursor)[0] == 400
assert "渠道凭据已变更" in north.submit(north_page, "catalog_channel_revoke", channel=north_id, generation=generation)
assert len(catalog(new_key)["items"]) == 3
revoke, = forms(north.load(north_page), "catalog_channel_revoke")
assert "该渠道已撤销" in north.submit(north_page, "catalog_channel_revoke",
                                         channel=north_id, generation=extract(revoke, "generation"))
assert feed(new_key)[0] == 401 and catalog(pond_key)["items"][0]["sku"] == "POND-01"
print("Channel withdrawal, credential rotation, and revocation passed.")
