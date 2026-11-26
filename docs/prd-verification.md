# PRD 逐项核对

需求以[正式基线 v2](requirements/01-产品总览-prd.md)为准。以下入口分别覆盖具体功能。编号场景的业务结果见[验收表](acceptance.md#prd-场景)。数据库脚本连实际 MySQL，网页脚本通过 PHP 服务提交真实表单。签名收款、退款与物流使用合成回执。

| 功能 | 实现与查验 |
| --- | --- |
| F01 入驻审核 | 经营主体及协议固定在审核记录。测试未签协议、越权审核、批准后改主体，以及停业后历史交易。[`reviewShop`](../src/Commerce.php) · [入驻与停业](../tests/integration.php) |
| F02 店铺授权 | 店主分配商品、履约、门店、客服、财务岗位；撤权后旧会话失效。测试跨店改权限、本店账单与跨店导出。[`addShopMember`](../src/Commerce.php) · [授权和账单网页流程](../tests/web_smoke.py) |
| F03 自营联营区分 | 运营可开自营店，普通商家需审核开店。测试平台身份、旧版收款主体及店铺类型。[`createPlatformShop`](../src/Commerce.php) · [自营验证](../tests/integration.php) |
| F04 多规格 SKU | 颜色及尺码写入商品发布版，订单行保存成交 SKU 和发布版。测试购买红色 M 码后蓝色规格库存不变。[目录](../src/CatalogOperations.php) · [规格成交验证](../tests/integration.php) |
| F05 商品审核 | 审核按源版本提交，商家不能自审。并行编辑中的旧申请不覆盖新版本。[目录](../src/CatalogOperations.php) · [发布版本验证](../tests/integration.php) |
| F06 分类检索与上下架 | 按类目、规格及渠道查询已发布商品，下架后旧报价也会拒单。[目录](../src/CatalogOperations.php) · [上下架网页流程](../tests/web_smoke.py) |
| F07 会员价 | 会员价先于活动门槛计算，成交价与商品标价各自保留。[`priceCart`](../src/Commerce.php) · [会员价验证](../tests/integration.php) |
| F08 跨店满减 | 跨店门槛、适用分类和叠加策略由活动版本决定，已领券保留原条款。[活动](../src/PromotionOperations.php) · [活动网页流程](../tests/web_smoke.py) |
| F09 优惠分摊 | ¥20 分为 ¥12 与 ¥8，按最小币种单位处理尾差；子单分别应付 ¥108、¥72。[`allocate`](../src/Commerce.php) · [实付金额验证](../tests/integration.php) |
| F10 购物车合并 | 游客车按会话隔离，登录后合并到买家账户，同 SKU 保留账户数量和勾选状态。两个独立买家会话可读取同一账户购物车。[`importSessionCart`](../src/Commerce.php) · [游客及跨设备网页流程](../tests/web_smoke.py) |
| F11 运费试算 | 按仓或按店计费，支持门槛、扩展与版本冻结。测试试算运费从 ¥8 变为 ¥6，买家单独确认后按新额下单。[计费规则](../src/FreightPolicyOperations.php) · [价格差异网页流程](../tests/web_smoke.py) |
| F12 价格快照 | 报价保存商品、促销、运费、分仓与购物车行版本。失效报价拒单，出现可接受的变更时生成新报价，买家确认后才下单。[`placeQuote`](../src/Commerce.php) · [过期与变动验证](../tests/integration.php) · [网页流程](../tests/web_smoke.py) |
| F13 库存预占 | 报价、订单及库存预占进行事务核对。最后一件由两个进程竞争时只有一个有效单。[库存](../src/InventoryOperations.php) · [并发验证](../tests/integration.php) |
| F14 预售额度 | 批次开放、占额、付款、取消及到货可追溯。验收到货与付款竞争，以及到货后在原门店提取。[预售](../src/PreorderOperations.php) · [预售验证](../tests/integration.php) |
| F15 取消释放 | 关闭待付单释放原普通库存、预售额度与积分占用。迟到实收进入退款处理。[`cancel`](../src/Commerce.php) · [释放与迟到收款验证](../tests/integration.php) |
| F16 支付意图 | 支付意图绑定订单、金额和收款渠道。错误币种及非原渠道的回执进入异常或拒绝。[支付渠道](../src/PaymentChannelOperations.php) · [回执验证](../tests/integration.php) |
| F17 回执去重 | 同流水三次提交只有一笔支付。响应丢失后可从财务页面查回原记录，同键改金额被拒绝。[中断与重试网页流程](../tests/web_smoke.py) · [渠道流水断言](../tests/integration.php) |
| F18 分商家订单 | 一张母单下包含不同商家的子单，子单实付和佣金分别核算。相同报价确认重放返回原订单。[`storeOrder`](../src/Commerce.php) · [跨店结账网页流程](../tests/web_smoke.py) |
| F19 分仓发货 | 同一商家从两仓发出两单包裹，未签完保持部分履约，完成后只记一次销售。[分仓发货](../src/ShippingOperations.php) · [签收网页流程](../tests/web_smoke.py) |
| F20 门店自提 | 提货码绑定原子单和门店，跨门店或过期码被拒，并发核销只有一次成功。[门店核销](../src/PickupOperations.php) · [并发扫码验证](../tests/integration.php) |
| F21 包裹追踪 | 回执可查外部事件号和原包裹，晚到事件不使已签收包裹回退。[物流回执](../src/ShippingOperations.php) · [渠道轨迹](../tests/channel_order_web.py) |
| F22 部分退款 | 按原行实付、已确认数量和原支付剩余可退额约束退款。超额时保留待退款状态且不记账。[退款](../src/ReturnOperations.php) · [部分退款与上限验证](../tests/integration.php) |
| F23 佣金冲回 | 收到退款回执后货款与原佣金分别冲回。A 退 ¥108 后，B 应结保持 ¥64.80。[退款记账](../src/ReturnOperations.php) · [两店结算验证](../tests/integration.php) |
| F24 商户结算与核对 | 店铺、周期、币种确定结算范围。复核与出纳分权，已关期退款进入后期；对账任务保留按行重试选集和原因。[结算](../src/SettlementOperations.php) · [渠道对账](../src/ChannelBillOperations.php) · [关期验证](../tests/integration.php) · [对账网页流程](../tests/web_smoke.py) |

## 专项核对

- 报价失效时返回明确原因，仍可交易的变更会在原购物车显示前后金额和新确认按钮。过期、库存不足或停业仍需重新选购或计价。[购物车](../views/cart.php)
- 部分账单行可按选集重新排队，过期选集中的其他状态、重复行号和不存在的行都有可复核结果。选集及状态保存在审计日志，原导入任务保留逐行结果。店铺账号无法重试平台账单。[渠道对账](../views/channel_bills.php)
- 部署、版本升级和备份恢复见[部署说明](deployment.md)及[历史数据核验](acceptance.md#备份恢复与旧版升级)。容量的测试环境、采样和阈值见[容量验收](capacity.md)。
