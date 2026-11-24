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

已在合作渠道收款的订单导入及物流明细推送需单独对接。合作方可按下面的接口主动查询物流。

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

## 包裹与物流

| 请求 | 返回 |
| --- | --- |
| `GET /channel-orders.php?reference=partner-ord-1&view=shipments` | `shipments` 列出该订单已发出的包裹。每个包裹含 `shipment_id`、`carrier`、`tracking`、`units`、`status`，以及发货、最近一次承运商事件和平台确认签收的时间 |
| `GET /channel-orders.php?reference=partner-ord-1&view=shipments&shipment_id=123` | `events` 列出该包裹已记录的轨迹，每条含 `event_id`、`status`、`occurred_at` 和 `received_at` |

每页至多 50 条，按包裹编号或事件入库编号升序返回。`next_after` 有值时用作下一次请求的 `after`。读到末页后可保存最后一个编号，以相同方式增量查询。晚到的承运商事件仍会出现在后续页，可按 `occurred_at` 显示实际发生顺序。

时间均为 UTC Unix 秒。包裹初始为 `dispatched`，随后可见 `in_transit`、`out_for_delivery`、`exception` 或 `delivered`。晚到事件保留在轨迹中，签收后的包裹仍显示为 `delivered`。未发生签收或承运商事件的对应时间为 `null`。未发货时包裹列表为空。订单与包裹必须属于当前渠道且互相匹配，跨店或其他订单的包裹编号返回 404。

## 退货与退款

已履约订单按原订单行申请退货，一次提交一个 SKU。沿用该渠道的订单密钥，店铺暂停接单时仍可办理历史订单售后。

对 `POST /channel-orders.php?action=return_request` 提交以下 JSON。

```json
{
  "reference": "partner-ord-1",
  "return_reference": "partner-return-1",
  "sku": "NORTH-01",
  "quantity": 1,
  "reason": "收到后发现尺寸不合适"
}
```

`return_reference` 在当前渠道内唯一，区分大小写，限 1–80 位 ASCII 字母、数字、连字符和下划线。数量取正整数，以原订单行剩余可退数量为上限。理由使用单行文字，最多 250 个 UTF-8 字节。首次申请返回 201。原样重试返回 200 及当前状态。已使用的退货单号改用其他订单、SKU、数量或理由，返回 409 `return_reference_conflict`。

| 操作 | 用法 |
| --- | --- |
| 查询售后 | `GET /channel-orders.php?reference=partner-ord-1&return_reference=partner-return-1`。查询须提供同一渠道内对应的一对单号 |
| 登记寄回 | `POST /channel-orders.php?action=return_dispatch`，传入 `{"reference":"partner-ord-1","return_reference":"partner-return-1","carrier":"parcel_v1","tracking":"RETURN-001"}`。商家同意后登记承运商和运单，重试时沿用原运单 |

申请初始为 `pending`。商家审核通过后返回 `return_authorized` 和 `return_address`，寄回登记后为 `return_in_transit`。商家验收后可读到实收件数 `accepted_quantity`、回库件数 `restocked_quantity`、验收备注和整数分金额 `refund_amount_minor`。退款处理中为 `refund_pending`，由支付渠道回执确认后变为 `refunded` 并提供 `refund_reference` 与 Unix 秒 `confirmed_at`。审核或验收拒绝时返回 `rejected` 与对应备注。各阶段尚未产生的地址、验收和退款字段为 `null`。

订单尚未履约返回 409 `order_not_fulfilled`，剩余数量不足返回 409 `return_unavailable`，原单缺少该 SKU 返回 404 `order_sku_not_found`。订单和退货单号未匹配或属于其他渠道时，查退货和登记寄回均返回 404 `return_not_found`。寄回状态不符或运单与已登记记录冲突时返回 409 `return_not_dispatchable`。退款和结算采用原订单付款与验收数据。

## 回归

仓库的 [HTTP 回归](../tests/channel_order_web.py)使用两套 PHP 进程和同一 MySQL 测试库，覆盖重复下单、跨渠道争抢库存、包裹轨迹分页、签收、退货验收与退款回执。运行时为两套 PHP 进程配置同一组[支付桥接](payment-channels.md)和[承运商桥接](carrier-adapters.md)样例，设置 `COMMERCE_CHANNEL_TEST_SECOND_URL` 指向第二个本机地址，再向测试脚本传入第一个地址。CI 会在两版 MySQL 上执行。
