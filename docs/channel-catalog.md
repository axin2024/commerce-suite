# 外部渠道商品与库存

一个店铺可以给合作商城、门店系统或其他商品展示端分别开通读取权限。每个渠道有自己的密钥和商品清单。同一个 SKU 可以加入多个渠道，价格和库存仍由商家中心维护。

## 开通

1. 店铺通过审核后，店主打开「商家中心 → 外部销售渠道」，输入代码和名称。代码在本店内唯一，格式为小写英文字母开头，后续可用小写英文字母、数字、下划线和短横线，最多 32 位。
2. 保存创建页上的密钥。离开或刷新页面后，可以通过轮换获得新密钥。轮换立即废止旧密钥与旧游标，渠道清单和商品关联编号保持有效。撤销为永久操作，关联仍可在商家中心查看。店主操作记入平台审计。
3. 把本店已审核且支持配送的在售 SKU 加入渠道。渠道只读取主动选入的商品，并在商品、店铺营业状态或渠道选择变化后返回相应状态。
4. 将密钥存入接入方的服务端配置。正式环境通过 HTTPS 发送请求，并确保代理层将 `Authorization` 请求头传给 PHP。密钥通过请求头传递。

## 接口

`GET /catalog-feed.php` 接收 `limit`（1–100，默认 100）和可选的 `cursor`。本机演示可以这样请求。

```sh
curl --fail --silent --show-error \
  -H "Authorization: Bearer $CATALOG_TOKEN" \
  'http://127.0.0.1:8080/catalog-feed.php?limit=100'
```

```json
{
  "version": 1,
  "channel": "partner-shop",
  "shop_status": "active",
  "currency": "CNY",
  "items": [
    {
      "listing_id": 12,
      "sku": "NORTH-01",
      "listing_revision": 1,
      "catalog_revision": 8,
      "inventory_revision": 4,
      "status": "listed",
      "available_units": 36,
      "product": {
        "name": "山形帆布托特包",
        "price_minor": 12000,
        "color": "砖红",
        "size": "M",
        "family": "山形帆布包",
        "category": "箱包"
      }
    }
  ],
  "next_cursor": null
}
```

| 字段 | 用法 |
| --- | --- |
| `listing_id` | 渠道内稳定的关联编号。用它更新外部记录，恢复渠道商品时编号沿用 |
| `sku` | 店铺内唯一的 SKU 编码 |
| `status` | `listed` 表示仍可展示。渠道移除、商品归档、审核通过的版本关闭配送或店铺暂停营业时返回 `withdrawn`，届时 `available_units` 为 0，`product` 为 `null` |
| `available_units` | 常规可售库存，等于所有仓库现货减去占用量。缺货商品仍可处于 `listed` 状态，库存为 0。预售额度仍在预售模块管理 |
| `product.price_minor` | 审核后生效的基础售价，单位为分。下单优惠、会员价格和运费由结算规则计算 |
| 三个 `*_revision` | 分别对应渠道选择、已发布商品版本和库存版本。接入方逐次应用 `status`，店铺暂停等变化可能发生在同一组版本号内 |
| `next_cursor` | 有后续页时返回不透明字符串。用同一密钥传回 `cursor`，直到返回 `null` |

每次完整扫描从省略 `cursor` 的请求开始。第一页确定本轮可见的关联范围，之后新加入的商品在下一轮出现。每页读取当时生效的状态，接入方可定期重新扫描。需要下单时，店主可以另外开通[渠道订单接口](channel-orders.md)，库存由平台订单事务更新。

| HTTP 状态码 | 含义 |
| --- | --- |
| 200 | 请求成功，清单为空时 `items` 返回空数组 |
| 400 | `limit` 格式无效或游标与当前密钥不匹配。密钥轮换后可从第一页重读 |
| 401 | 密钥缺失、错误或失效 |
| 405 | 接口仅支持 GET |
| 503 | 服务暂时不可用，可按接入方的退避策略重试 |

在独立测试库加载种子数据，并启用沙箱支付或[支付桥接样例](payment-channels.md)。启动本机服务后运行 `python tests/catalog_channel_web.py`，回归会占用和释放库存、切换商品与店铺状态，并轮换渠道密钥。
