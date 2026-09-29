# PHPMailer Injector

Haraka SMTP 邮件注入程序，使用 [PHPMailer](https://github.com/PHPMailer/PHPMailer) 通过本地 SMTP（127.0.0.1:587）发送邮件。

与 Warship GUI 现有流程兼容：

```bash
{binary} send --config /path/to/config.yaml
{binary} --help
```

输出 `progress.json` / `result.json` 格式与 Go 版 `pmta-injector` 一致，GUI 监控无需改动。

## 本地开发

```bash
cd PHPMailer-injector
composer install
php injector.php --help
php injector.php send --config /path/to/config.yaml
```

## 打包发布

```bash
cd PHPMailer-injector
composer install --no-dev --optimize-autoloader
cd ..
tar -czf phpmailer-injector.tar.gz phpmailer-injector/
sha256sum phpmailer-injector.tar.gz
```

将 tar 上传到可下载 URL，并在 `gui/assets/injector-source.txt` 中更新：

```
INJECTOR_SOURCE_URL=https://example.com/phpmailer-injector.tar.gz
INJECTOR_SOURCE_SHA256=<sha256>
```

## VPS 部署

GUI 使用 `gui/assets/hrkdeploy.sh`（由 `deploy/hrkdeploy-php.sh` 同步），环境变量与原先 Go 版相同：`VAR_INSTALL_DIR`、`VAR_BINARY_NAME`、`VAR_TASKS_BASE_DIR`、`SRC_TAR_URL`、`SRC_TAR_SHA256`。

源码地址与校验和写在 `gui/assets/injector-source.txt`。

## 功能

- YAML 配置（与 `config_yaml.py` 生成格式兼容）
- Mini 模式 MIME：`standard`（单体 HTML / CID 时 related）/ `alternative`（TXT+HTML）/ `full`（无附件等同 alternative；有真实附件时 mixed 且不含 alternative）
- CSV 收件人、模板变量（`{DATE_JP}`、`{RANDOM:8}`、`{url}` 等）
- PHPMailer SMTP 认证发送（STARTTLS/SSL、`skip_verify`、HELO 根域、KeepAlive 连接复用）
- CID 内嵌图片、附件、随机生成附件
- 零宽字符（zero_width）、`{{zw_文本}}` / `{{rand_N}}` 变量
- HTML 指纹扰动（`html_mutator`）
- BCC 独立邮件
- 速率限制、随机间隔（读取 `min_interval` / `max_interval`）
- STOP 文件优雅停止
- progress.json / result.json 原子写入

SMTP 段与 GUI 生成的 YAML 对齐：`skip_verify`、`max_conn`（>0 时 KeepAlive 复用连接）、`security: STARTTLS|SSL`（未写则按 `use_tls` / 端口 465 推断）。HELO 使用发件地址的根域。非 UTF-8 字符集会做 `mb_convert_encoding`（可用 `encoding.disable_charset_convert` 关闭）。

## 依赖

- PHP >= 8.1（mbstring 扩展）
- Composer 包：phpmailer/phpmailer 7.0.2、symfony/yaml ^6.4
