# 外部渠道订单接入

合作商城可读取店铺选入的商品，再用独立的订单密钥询价、提交订单和领取平台收银链接。交易沿用平台支付、库存、分仓履约和商户结算。适用于由 Commerce Suite 收款并履约的合作渠道。

## 开通

1. 部署者配置[支付渠道](payment-channels.md)，完成真实收银和验签的联调。
2. 店主在「商家中心 → 外部销售渠道」创建渠道并选择可配送 SKU，随后在「接收渠道订单」签发订单密钥。读取目录使用 `cf1_` 密钥，订单使用 `co1_` 密钥。合作方只在服务端持有订单密钥。
3. 合作方通过 HTTPS 使用 `Authorization: Bearer …`。轮换或关闭订单接口会废止原订单密钥；关闭后重新签发可继续查询历史订单。撤销父渠道会同时停用该渠道的两类密钥。已经写入的订单仍按正常流程处理。

## 询价与提交

对 `POST /channel-orders.php?action=quote` 提交以下 JSON。最多 30 个 SKU，每个数量为 1–99 的整数。SKU 不区分大小写，同一个 SKU 只提交一次。配送字段使用单行内容，可填写收件人、联系电话和地址，最多 250 个 UTF-8 字节。

```json
{
  "items": [{"sku": "NORTH-01", "quantity": 1}],
  "destination": "测试收件人 · 验收路 1 号"
}
```

响应包含 `items`（各 SKU、数量及单价）、`subtotal_minor`、`shipping_minor`、`total_minor` 和 `quote_version`。金额为 CNY 整数分。系统使用已审核的基础售价和店铺运费规则，渠道订单范围为本店常规配送商品。报价调用结束后库存仍可被其他订单购买。

对 `POST /channel-orders.php?action=place` 发送同样的商品和地址，再附加以下两个字段。

| 字段 | 用法 |
| --- | --- |
| `reference` | 合作方订单号。每个渠道内唯一，区分大小写，限 1–80 位 ASCII 字母、数字、连字符和下划线 |
| `quote_version` | 复制询价响应的原值。服务端重新锁定并检查商品、价格、运费和分仓，再据此核对报价 |

第一次创建返回 HTTP 201。同一渠道、同一单号、同一内容重复提交返回 HTTP 200 和当前订单状态。提交超时可原样重试。单号已创建后，以同一单号改地址、商品或报价会返回 `reference_conflict`。取消后的单号也保留，重新下单时使用新的单号。行顺序、SKU 大小写和地址首尾空格在比较之前统一处理。

价格、运费或分仓变化时，可用相同单号重新询价并提交，前提是该单号尚未创建。站内与外部渠道使用同一份仓库库存。库存不足时，系统拒绝整张订单，合作方可修改数量后重新询价。

## 支付、查询与取消

| 请求 | 用法 |
| --- | --- |
| `POST /channel-orders.php?action=checkout` | 传入 `{"reference":"partner-ord-1"}`。待付款且未过期时返回 HTTPS `checkout_url`。合作方把买家引导到该链接 |
| `GET /channel-orders.php?reference=partner-ord-1` | 返回母单与商家单编号、订单与履约状态、金额和 Unix 秒 `expires_at` |
| `POST /channel-orders.php?action=cancel` | 传入相同的单号 JSON。待付款订单取消后释放库存，重复取消返回当前已取消订单。已付款订单进入售后流程 |

POST 使用 `Content-Type: application/json`，上限 16 KiB。订单创建后预留 15 分钟。后台任务处理过期订单，释放库存。只有平台支付渠道的验签实收回执可以确认付款。支付后商家可在履约工作台和历史订单看到渠道代码及外部单号，按原流程发货。合作方按外部单号查询 `unpaid`、`paid`、`cancelled`，以及 `fulfillment_status` 的发货和签收状态。

目前接口覆盖下单到发货状态查询，合作方的退货申请、已在渠道收款的订单导入及物流明细推送需另接。

| 状态 | 常见处理 |
| --- | --- |
| 400、413、415、422 | 检查 JSON、请求体大小和字段 |
| 401 | 核对订单密钥与渠道权限 |
| 404 | 当前渠道没有该单号 |
| 409 `quote_changed` | 重新询价后再提交 |
| 409 `sku_unavailable`、`shop_unavailable`、`order_unavailable` | 检查目录、店铺营业状态和库存，给买家展示更新后的选择 |
| 409 `reference_conflict` | 查原单核对差异 |
| 409 `order_not_payable`、`order_already_paid` | 先查询当前订单状态，再决定取消或走售后 |
| 503 | 检查收银配置或按退避重试。重试提交时沿用原单号和原内容 |

仓库的 [HTTP 回归](../tests/channel_order_web.py)使用两套 PHP 进程和同一 MySQL 测试库，同时提交相同单号，以及让站内和外部订单竞争同一件库存。运行时为两套 PHP 进程配置同一组[支付桥接](payment-channels.md)和[承运商桥接](carrier-adapters.md)样例，设置 `COMMERCE_CHANNEL_TEST_SECOND_URL` 指向第二个本机地址，再向测试脚本传入第一个地址。CI 会在两版 MySQL 上执行。
