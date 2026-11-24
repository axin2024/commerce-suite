# 发行

发行说明应记录版本、数据库迁移、已知限制和实测的运行环境。先在空库执行迁移和集成测试，再从上一发布版的样例库恢复并升级。升级验收至少要查回一张已成交订单和对应分录。迁移停写、备份和回退办法见[部署与恢复](deployment.md)。

用演示数据跑完 README 的跨店交易和售后。实付应为 ¥180，两张子单分别为 ¥108、¥72。重复投递同一支付流水三次，只确认一笔支付。A 店全退后核对 B 店应结仍为 ¥64.80。对照[业务验收](acceptance.md)中的 PRD 场景，并附[容量用例](capacity.md)的运行环境及结果。

发布前更新[版本变更](../CHANGELOG.md)。从待发布提交生成源码包后，在新目录解包，用空库按 README 启动并运行回归。确认包内含许可、安装文档、配置样例、迁移和演示脚本。环境文件、数据库转储和实际业务日志保留在部署侧。依赖和素材来源见[支持范围](../SUPPORT.md#许可)。

## 镜像核对

[GitHub](https://github.com/axin2024/commerce-suite) 与 [Gitee](https://gitee.com/hongxin01/commerce-suite) 发布同一 Git 标签。执行以下命令检查已发布的标签与提交，示例版本为 `v0.1.0`。

```sh
tag=v0.1.0
git rev-parse "$tag^{commit}"
git ls-remote github "refs/tags/$tag" "refs/tags/$tag^{}"
git ls-remote gitee "refs/tags/$tag" "refs/tags/$tag^{}"
```

带注释的标签比较 `^{}` 行的提交。轻量标签比较标签所在行的提交。两端都应与本地提交一致。若向两端附加源码包，从同一标签生成一份归档，在两端上传相同字节，并附上 SHA-256。

```sh
git archive --format=zip --prefix="commerce-suite-$tag/" -o "commerce-suite-$tag.zip" "$tag"
sha256sum "commerce-suite-$tag.zip"
```

平台自动生成的源码压缩包可能有不同的封装信息，校验这类下载时以 Git 提交和 Git tree 为准。发布记录应随版本保留迁移和回退方式，有意修改接口时同时说明旧格式的读取范围。
