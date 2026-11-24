# 承运商接入

商家发货前先在承运商侧取得运单号，然后在商家中心按仓库登记包裹。订单页显示运输中、派送中、配送异常或已签收。只有所有商品均已发出、且所有包裹签收时，子单才完成履约并产生一条商家销售分录。

## 安装适配器

`COMMERCE_CARRIER_EXTENSION` 指向应用进程可读的本地 PHP 文件。文件返回以承运商代码为键的数组，每个值是 `Closure(string $body, array $headers): ?array`。在 Compose 中使用容器内路径。商家登记包裹时填写对应代码，如 `parcel_v1`。代码匹配 `[a-z][a-z0-9_-]{0,31}`，`demo` 和 `sandbox` 保留给演示。

推送地址为 `POST /carrier-webhook.php?carrier=parcel_v1`。回调处理器接收至多 64 KiB 原始正文和小写 HTTP 头。适配器先按签名规范验证原始数据，再返回以下四个字段。可以抛出 `InvalidCarrierSignature` 拒绝验签失败的请求，或返回 `null` 忽略已验签的无关通知。部署端需要将回调路径通过 HTTPS 提供给承运商或其对接服务。

| 字段 | 要求 |
| --- | --- |
| `type` | `in_transit`、`out_for_delivery`、`exception` 或 `delivered` |
| `tracking` | 商家登记的原始运单号，区分大小写 |
| `reference` | 该承运商为事件分配的稳定编号，重试时复用 |
| `occurred_at` | UTC Unix 秒，整数。比服务端时间晚 5 分钟以上会被拒绝 |

运单号和事件编号均为 1 至 80 个 ASCII 字母、数字、下划线或连字符。按来源、承运商和事件编号查重。同键同内容返回原 `event_id`。改变运单、状态或发生时间返回 HTTP 409。JSON 字段顺序不参与去重，事件摘要按适配器输出的标准字段计算。未登记的运单同样返回 409，可在商家登记后用原事件编号补投。

签收前按事件发生时间展示最新状态，同一秒按本地事件编号取较新的一项。已签收是终态，晚到的运输和异常回执仍可留存，订单页继续显示已签收。销售分录与最终签收状态使用服务端入账时间。临时处理失败返回 503，可退避后使用原业务字段重试。HTTP 200 的 `status` 表示本次接收的事件类型。

已配置承运商的包裹在登记时绑定外部回执。启用外部适配器后，旧库中仍未签收的同代码包裹可接收该承运商的签名回执。旧沙箱签收保留原事件编号和签收时间，仍可用沙箱重放。关闭外部适配器后，已绑定的包裹继续等待该承运商的回执，启用沙箱也无法代签。

## 桥接示例

[carrier-bridge.php](../examples/carrier-bridge.php) 实现 `parcel_v1`。在自有桥接服务中把承运商事件转换为上表格式，并通过请求头发送 `X-Carrier-Timestamp` 和 `X-Carrier-Signature`。签名为 `HMAC-SHA256(时间戳 + "." + 原始正文, 密钥)` 的小写十六进制值，时间戳为发出请求时的十位 Unix 秒，允许前后各 300 秒。延后重试时刷新请求时间戳与签名，事件发生时间和编号保持原值。

本机将 `COMMERCE_CARRIER_EXTENSION` 设为 `examples/carrier-bridge.php` 的绝对路径。使用 Compose 时设为 `/app/examples/carrier-bridge.php`。应用和对接服务配置相同且至少 32 字节的 `COMMERCE_CARRIER_WEBHOOK_SECRET`。将签名测试值和实际部署密钥分开。

空测试库按 [README](../README.md#本机运行)迁移并导入种子，配置[支付桥接](payment-channels.md)和承运商桥接后以 `APP_DEMO=0` 启动应用。运行 `python tests/carrier_web.py http://127.0.0.1:8080` 可从网页新建配送订单与两个包裹。测试包含签名通过与拒绝、同号内容冲突、乱序派送、异常、最终签收和单次入账。承运商面单申请与具体供应商的 API 由部署端对接。
