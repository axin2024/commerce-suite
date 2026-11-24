# 参与贡献

选择一个镜像提交问题或代码即可。问题复现请附上版本、环境、最小样例及预期结果。订单、库存和账务问题请提供操作前后的数量或整数分金额，便于检查状态转移。

[GitHub Issues](https://github.com/axin2024/commerce-suite/issues) · [Gitee Issues](https://gitee.com/hongxin01/commerce-suite/issues) · [支持范围](SUPPORT.md)

## 本地验证

按 [README](README.md#本机运行)安装 64 位 PHP 和 MySQL。在独立、名称以 `_test` 结尾的空库上完成迁移后运行集成测试。网页回归另用一份空库，导入演示种子后启动服务。启用签名回执场景时，在应用和测试进程设置相同的沙箱密钥。

```sh
php bin/commerce.php migrate
php tests/integration.php
python3 tests/web_smoke.py http://127.0.0.1:8080
```

金额使用整数分，涉及多个店铺时要同时核对母单、各店子单和所属库存。变更迁移时，分别在空库和上一发布版本的样例库执行两次迁移，核对历史订单、回执或账务仍可读取。用合成账号、运单和支付流水提交公开样例。

## 改动与扩展

提交合并请求时说明业务问题、影响角色、升级方式和本地执行结果。涉及金额、租户归属或重复提交时，请写明测试所断言的订单金额、状态或分录。

[运费规则](docs/freight.md)、[支付渠道](docs/payment-channels.md)和[营销规则](docs/promotions.md)包含可运行的扩展示例。扩展改动应提供一组成功输入和一组拒绝输入，并说明策略版本、权限、重复调用、取消与失败行为。

发行前的验证与镜像核对见[发行手册](docs/releasing.md)。
