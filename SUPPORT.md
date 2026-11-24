# 支持范围

公开版本以仓库标签为准。首个标签为 `v0.1.0`，功能和安装要求请查对应标签下的 README。主分支中的数据库迁移、演示说明和测试随开发更新，升级前建议从备份在隔离环境验证目标版本。

## 主分支运行环境

| 依赖 | 要求与验证 |
| --- | --- |
| PHP | 64 位 PHP 8.2 或以上，启用 `pdo_mysql`。本机验收使用 8.2.30 |
| MySQL | 新部署使用 8.4 LTS。本机验收覆盖 8.4.13 和 8.0.46 |
| Python | 仅运行网页回归与订单列表压测时需要 Python 3 |
| Docker Compose | 可选，提供应用、迁移、后台任务和数据库的启动编排 |

## 外部服务

| 服务 | 当前接口 |
| --- | --- |
| 收款与退款 | 演示模式可重放签名回执。[支付渠道扩展](docs/payment-channels.md)可接入外部收银台、实收及退款回执。仓库提供桥接协议与回放夹具，服务商 API 需由部署者接入 |
| 快递 | 支持录入运单、演示签收及[承运商签名跟踪适配器](docs/carrier-adapters.md)。面单申请和具体服务商对接由部署者配置 |
| 银行打款 | 出纳登记线下打款结果，银行流水由使用者核对 |
| 外部商品目录与库存 | [渠道读取接口](docs/channel-catalog.md)提供按店授权的商品和可售库存。合作方可按游标定期拉取 |
| 渠道订单与账单 | [渠道订单接口](docs/channel-orders.md)可下单、发起平台收银、查单、取消待付订单、查询包裹轨迹并办理退货。财务可上传本地格式的 CSV 对账。外部已收款订单需另行接入 |

备份、恢复和切换版本见[部署与恢复](docs/deployment.md)。提交问题前请删去 Cookie、数据库密码、个人信息与真实业务流水。安全问题请通过私密方式联系仓库所有者。

## 许可

项目源码、文档及合成业务样例随 [Apache License 2.0](LICENSE) 发行。源码包中的素材和外部依赖如下。

| 内容 | 来源与分发方式 |
| --- | --- |
| 页面样式与自提条码 | 样式及条码绘制代码随项目分发。文字使用设备的系统字体 |
| 演示店铺、商品、订单与账单 | 业务样例为合成数据，来自项目种子、测试及 `public/channel-bill-sample.csv` |
| 商品图片、字体文件、音视频和模型权重 | 源码包无此类文件 |
| PHP 和 PDO MySQL | 运行时由部署者安装。[PHP 许可](https://www.php.net/license/) |
| MySQL Community Server | 运行时由部署者安装。[MySQL 许可文件](https://github.com/mysql/mysql-server/blob/trunk/LICENSE) |
| Python 3 | 运行网页回归和压测时由开发者安装。[Python 许可](https://docs.python.org/3/license.html) |
| 容器镜像 | Compose 启动或构建时从镜像源取得。[PHP 官方镜像](https://github.com/docker-library/php) · [MySQL 官方镜像](https://github.com/docker-library/mysql) |
| CI 工具 | 由工作流加载。[actions/checkout](https://github.com/actions/checkout) · [shivammathur/setup-php](https://github.com/shivammathur/setup-php) |
