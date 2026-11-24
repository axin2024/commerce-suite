# Commerce Suite

多商户全渠道电商系统。买家可以把不同店铺的商品一起结算，商家分别履约，财务按店铺核对销售、佣金、退款冲正与结算批次。

## 功能

| 业务 | 已实现 |
| --- | --- |
| 平台运营 | 入驻审核、收款主体及协议留痕、自营店铺、暂停及恢复接单、操作审计 |
| 商户工作台 | 店主配置商品、履约、售后和财务岗位，各店独立查账 |
| 商品目录 | 分类与系列、独立规格 SKU、商品版本审核、按渠道发布、下架及分类和规格搜索 |
| 会员营销 | SKU 会员价、跨店满减、按分类限定范围、单次券、活动版本、叠加规则与分摊 |
| 购物车 | 多设备共用、按行勾选结算、按店铺计运费、十分钟报价快照 |
| 库存 | 可售量计算、下单预留、付款扣减、主动取消、超时释放 |
| 订单支付 | 母子单、支付意图、沙箱签名回调、流水去重、迟到及重复实收处置 |
| 履约 | 配送单号、自提核验编号、按店铺查询和确认履约 |
| 售后 | 按订单行申请部分退款、商家审批、剩余可退量、按原价快照冲正 |
| 财务 | 销售与退款分录、佣金、未结余额、按店铺生成结算批次及负余额结转 |

## 本机运行

需要 64 位 PHP 8.2 或以上、`pdo_mysql` 和 MySQL 8.0.16 或以上。本项目无需安装 Composer 依赖。先创建空库 `commerce_suite` 和有该库权限的专用账号。

```sh
export COMMERCE_DSN='mysql:host=127.0.0.1;port=3306;dbname=commerce_suite;charset=utf8mb4'
export COMMERCE_DB_USER='commerce'
export COMMERCE_DB_PASSWORD='<本地数据库密码>'
export APP_DEMO=1

php bin/commerce.php migrate
php bin/commerce.php seed
php -S 127.0.0.1:8080 -t public
```

PowerShell 使用 `$env:变量名 = '值'` 设置环境变量。Windows 如未加载 PDO MySQL，请在 `php.ini` 启用 `extension=pdo_mysql`。打开 [本机商城](http://127.0.0.1:8080/)。

## Docker Compose

设置 `COMMERCE_DB_PASSWORD`、`MYSQL_ROOT_PASSWORD` 和 `APP_DEMO=1`，再运行以下命令。Compose 默认仅将 Web 端口绑定至本机。

```sh
docker compose up -d --build
docker compose exec app php bin/commerce.php migrate
docker compose exec app php bin/commerce.php seed
```

## 演示

演示密码统一为 `CommerceDemo-2026`。演示种子要求空用户表和 `APP_DEMO=1`，应用也通过这一变量控制模拟支付入口。

| 角色 | 邮箱 |
| --- | --- |
| 买家 | `buyer@example.test` |
| 商家 A | `north@example.test` |
| 商家 B | `pond@example.test` |
| 平台运营 | `admin@example.test` |
| 财务 | `finance@example.test` |

1. 买家在商城点「领券」，添加山形帆布托特包（¥120）和湖蓝陶瓷早餐杯（¥80）。购物车选门店自提和已领的跨店券，再计算费用，应付 ¥180。
2. 提交报价并模拟支付。两个商家分别确认履约，子订单金额是 ¥108 和 ¥72。
3. 买家给托特包发起退款，商家 A 审批。退款 ¥108 同时冲正 ¥10.80 佣金。
4. 财务查看结算分录并分别生成批次。商家 A 应结 ¥0，商家 B 应结 ¥64.80。

### 回放沙箱支付

为本地应用和回放终端设置同一个至少 32 字节的 `COMMERCE_SANDBOX_WEBHOOK_SECRET`，并设置 `APP_DEMO=1`。在待付款订单页复制沙箱支付意图，以分为单位回放收款。以下示例对应应付 ¥180 的订单。

```sh
php bin/sandbox-payment.php 8080 '<支付意图>' example-receipt-001 18000
```

重复执行同一条命令三次，回执编号和支付编号保持一致。可在运营台查看回执以及待核对的金额或币种差异；财务可登记迟到或重复实收的模拟退款回执。接口签名和返回格式见[架构与业务规则](docs/architecture.md#沙箱支付回执)。

## 运维与验证

- 从 v0.1.0 升级时，先备份数据库，停止应用写入，再运行 `php bin/commerce.php migrate` 并重启应用。升级保留原有订单、分录和账号，将既有商户账号设为本店店主。旧版未登记收款主体及协议的记录保留为未登记，待审店铺可由店主补交。仍有效的旧买家会话会在首次访问时合并购物车，旧购物车报价需重新计算。
- 已上架的旧商品保留原 SKU、价格、库存和配送及自提渠道；旧记录的分类和规格可由商户补录并提交平台审核。
- 每分钟运行一次 `php bin/commerce.php expire`，单次最多释放 200 张超时订单，批量积压时可连续执行。
- 创建自有运营账号时，通过环境变量 `COMMERCE_OPERATOR_PASSWORD` 传入密码，并运行 `php bin/commerce.php operator admin operator@example.com`。支持的角色为 `admin`、`finance`。
- 在独立、库名以 `_test` 结尾的 MySQL 库设置 `APP_DEMO=1` 并执行迁移，然后运行 `php tests/integration.php`。并发库存用例需要 CLI 支持 `proc_open`。
- 启动带种子数据的本机演示后，可用 `python tests/web_smoke.py` 验证各角色通过网页完成交易。
- 支付及退款回放使用独立演示数据库。真实支付网关、银行打款、第三方渠道订单同步、预售和多仓属于后续范围。

[架构与业务规则](docs/architecture.md) · [Apache License 2.0](LICENSE)
