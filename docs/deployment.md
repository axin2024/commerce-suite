# 部署与恢复

Compose 默认运行 MySQL 8.4 LTS。迁移服务在数据库可连接后执行，成功退出后启动 Web 与后台任务。健康检查确认 Web 服务能查询数据库且已应用的迁移与当前代码一致。迁移服务正常退出后可在 `docker compose ps -a` 中看到退出码 0。

## 配置

| 变量 | 用途 |
| --- | --- |
| `COMMERCE_DB_PASSWORD` | 应用数据库账号密码，Compose 必填 |
| `MYSQL_ROOT_PASSWORD` | Compose 初始化数据库时使用的管理密码 |
| `COMMERCE_MYSQL_IMAGE` | Compose 默认 `mysql:8.4`，已有 8.0 数据卷可指定 `mysql:8.0` |
| `COMMERCE_HTTP_PORT` | 宿主机回环地址绑定的端口，默认 8080 |
| `COMMERCE_DSN`、`COMMERCE_DB_USER` | 原生部署填写 MySQL DSN 与数据库账号，Compose 使用内部服务 `db` 和账号 `commerce` |
| `APP_DEMO` | 默认关闭，设为 1 允许导入演示种子和回放沙箱支付、签收及退款 |
| `COMMERCE_SANDBOX_WEBHOOK_SECRET`、`COMMERCE_SANDBOX_SHIPPING_SECRET`、`COMMERCE_SANDBOX_REFUND_SECRET` | 各自启用对应演示回放接口，每个至少 32 字节 |
| `COMMERCE_SECURE_COOKIES` | 在 HTTPS 反向代理部署时设为 1，让浏览器只在 HTTPS 下发送会话 Cookie |
| `COMMERCE_WORK_INTERVAL_SECONDS` | 后台任务每轮结束后的等待时间，默认 60，允许 1 至 3600 秒 |
| `COMMERCE_FREIGHT_EXTENSION` | 可选，应用进程能读取的 PHP 计费文件绝对路径，详见[运费扩展](freight.md) |
| `COMMERCE_PROMOTION_EXTENSION` | 可选，应用进程能读取的 PHP 优惠策略文件绝对路径，详见[营销扩展](promotions.md) |
| `COMMERCE_PAYMENT_EXTENSION`、`COMMERCE_CHECKOUT_CHANNEL` | 加载并选用支付渠道。关闭演示模式后，正金额订单需要配置收银渠道，详见[支付渠道接入](payment-channels.md) |
| `COMMERCE_OPERATOR_PASSWORD` | 创建运营账号的临时密码，在执行账号创建命令时单独传入 |

Compose 初始化变量只对空数据卷生效。已有库需要在 MySQL 中更新账号密码，并同时更新应用配置。对外部署应保持 `APP_DEMO=0`，只暴露 HTTPS 代理端口。登录和注册的限流由入口代理配置。PHP 内置服务器适用于本机演示与验收，正式环境请使用 PHP-FPM 和反向代理。

## 创建运营账号

空库迁移后，可在原生终端先设置 `COMMERCE_OPERATOR_PASSWORD` 再运行 `php bin/commerce.php operator admin operator@example.com`。通过 Compose 运行时，把已导出的变量传给一次性命令。

```sh
docker compose exec -e COMMERCE_OPERATOR_PASSWORD app php bin/commerce.php operator admin operator@example.com
```

为财务和出纳分别执行同一命令，将角色换成 `finance` 和 `payout`，并使用各自邮箱和密码。

## 健康检查与后台任务

- `GET /health.php` 就绪时返回 HTTP 200 和 `{"status":"ready"}`。数据库不可用、迁移版本不符或扩展配置无法加载时返回 HTTP 503 和 `{"status":"unavailable"}`。支持 `HEAD`。
- `php bin/commerce.php health` 在原生终端验证同样的数据库条件。Compose 下可执行 `docker compose exec app php bin/commerce.php health`。
- `php bin/worker.php` 每轮连接数据库并检查迁移状态，依次执行过期订单释放和渠道账单对账。单项失败会记日志，剩余任务仍会运行，下一轮重新连库。
- `php bin/worker.php --once` 运行一轮，适合系统定时任务。任一任务失败时返回非零状态。需要排查积压时，可查看 `docker compose logs worker`、待付款订单的过期时间和渠道账单的待核对状态。

## 备份与隔离恢复

下面是 POSIX Shell 的 Compose 示例。应用表使用 InnoDB。备份允许正常业务写入，备份期间暂停执行 DDL。跨版本切换前先停写，再导出作为切换基线。SQL 转储包含订单与账号数据，应存放在受控目录。

```sh
umask 077
docker compose exec -T db sh -c 'exec mysqldump --user="$MYSQL_USER" --password="$MYSQL_PASSWORD" --single-transaction --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "$MYSQL_DATABASE"' > commerce.sql.tmp &&
  test -s commerce.sql.tmp &&
  mv commerce.sql.tmp commerce.sql &&
  sha256sum commerce.sql > commerce.sql.sha256
```

检查导出命令退出状态和文件摘要。用此前未使用的 Compose 项目名和本机端口，创建独立测试卷。导入前检查库中没有任何表。示例沿用源库的数据库名，Compose 项目名决定卷的身份。

```sh
export COMMERCE_HTTP_PORT=8081
export COMMERCE_MYSQL_IMAGE=mysql:8.4
restore_project=commerce-restore
sha256sum -c commerce.sql.sha256 &&
  docker compose -p "$restore_project" up -d --wait db &&
  tables=$(docker compose -p "$restore_project" exec -T db sh -c 'exec mysql --user="$MYSQL_USER" --password="$MYSQL_PASSWORD" --batch --skip-column-names --execute="SHOW TABLES" "$MYSQL_DATABASE"') &&
  test -z "$tables" &&
  docker compose -p "$restore_project" exec -T db sh -c 'exec mysql --user="$MYSQL_USER" --password="$MYSQL_PASSWORD" --default-character-set=utf8mb4 "$MYSQL_DATABASE"' < commerce.sql &&
  docker compose -p "$restore_project" up -d --build --wait &&
  curl -fsS http://127.0.0.1:8081/health.php
```

恢复库保留原有数据，无需导入演示种子。用备份对应的业务样本核对订单的总实付和子单拆分、退款回执、库存预占以及结算净额。演示基准为总实付 ¥180、子单 ¥108 与 ¥72，A 店全退 ¥108 后 B 店应结 ¥64.80。备份命令与逻辑恢复机制参见 [MySQL 的 mysqldump 手册](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)。

## 升级

1. 记录当前代码、应用镜像和数据库版本。停止 Web、后台任务及外部写入方，再完成一次业务库备份。Compose 下用 `docker compose stop app worker` 停止应用进程。
2. 先在隔离环境从该备份恢复并执行目标版本迁移。使用 MySQL 8.0 时保持原卷的 8.0 镜像配置，转到 8.4 时按上节还原到新实例。
3. 确认版本、健康状态、库存、订单和账务后切换。保留切换前的镜像和数据库备份。若需回退，在停写后把升级前备份恢复至隔离库并用原应用版本验证，再切回服务。

## 验收记录

2026-11-24 使用 PHP 8.2.30，在 Windows 上分别启动 MySQL 8.0.46 和 MySQL 8.4.13 独立实例。两个版本各通过 438 项集成检查及网页交易回归。空库的 HTTP 探测返回 503，完成迁移后返回 200。常驻后台进程在等待迁移后自行恢复，关闭一张过期订单并释放预占，同时核对一条账单记录。

- 从 `v0.1.0` 在 MySQL 8.0.46 生成历史记录，恢复到 MySQL 8.4.13 后执行所有迁移。逐条核对 3 张母单、4 张子单、1 笔支付、3 条账务分录及 2 张结算单。再次执行迁移时返回数据库已是当前版本。
- 从完整网页交易的 MySQL 8.0.46 库恢复到 MySQL 8.4.13。实付 ¥180，子单 ¥108 和 ¥72，退款分别冲回 ¥108 和 ¥72，佣金冲正为 ¥10.80 和 ¥7.20。B 店后续退款保留了此前 ¥64.80 的打款记录。源库和恢复库的对应字段一致。
- Compose 5.8.0 已验证服务依赖及 8.0 镜像覆盖配置。容器启动验收由 GitHub Actions 的 Compose 任务执行。
