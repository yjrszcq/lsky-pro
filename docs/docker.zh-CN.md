# Docker 部署与开发

运行、开发和测试统一使用 **PHP 8.5.12**。镜像从当前仓库源码构建，包含 Apache、Imagick、BCMath、mbstring、FTP、Redis、ZIP、MySQL/PostgreSQL/SQLite PDO 驱动，并在构建时安装锁定的 Composer 依赖和编译前端资源。

应用源码放在镜像内，仅持久化运行数据，更新镜像即可更新代码。

## 首次部署

```sh
docker compose up -d --build
```

访问 `http://localhost:8089` 完成安装。选择 SQLite 时，数据库路径留空，数据库会创建在 `/var/www/html/storage/database.sqlite`；设置管理员邮箱和密码后提交。也可以选择自己提供的 MySQL 或 PostgreSQL 服务。

修改宿主机端口和对外地址：

```sh
LSKY_PORT=9080 LSKY_URL=https://images.example.com docker compose up -d --build
```

修改地址后，已安装站点的存储策略访问 URL 也应通过后台相应更新。HTTPS 由反向代理终止，代理需传递原始 `Host`、`X-Forwarded-Proto` 和 `X-Forwarded-For`。容器默认监听 HTTP 8089；直接运行镜像时，可用 `WEB_PORT` 修改容器监听端口，并同步修改端口映射。

```sh
docker run -d --name lsky-pro --restart unless-stopped \
  -p 8089:8089 \
  -e APP_URL=http://localhost:8089 \
  -v lsky-data:/var/www/html/storage \
  lsky-pro:php8.5.12
```

镜像内未打包 SQL Server 的 ODBC/PDO 驱动。需要 SQL Server 时，应在派生镜像中安装相应驱动并另行验证。

## 持久化、备份与更新

`lsky-data` 卷保存配置和加密密钥（`storage/.env`）、安装状态、SQLite 数据库、上传文件、缩略图、会话、日志及水印资源。根目录 `.env`、`installed.lock` 和公开图片目录通过符号链接指向该卷。容器重建时会恢复本地存储策略链接。

不要将整个旧应用目录挂载到 `/var/www/html`，否则会遮蔽镜像中的新版代码。自定义本地存储根目录如果不在 `storage` 内，需要额外挂载并备份。外部数据库和云存储需分别备份。

更新前备份数据卷和数据库，保留可回退的旧镜像。使用 SQLite 时可先停止应用再备份卷，避免数据库写入过程中的不一致。

```sh
docker compose stop app
docker run --rm -v lsky-pro_lsky-data:/data:ro -v "$PWD":/backup \
  alpine tar czf /backup/lsky-data-backup.tar.gz -C /data .
```

卷名前缀取决于 Compose 项目名，用 `docker volume ls` 确认实际名称，替换示例中的 `lsky-pro_lsky-data`。

从源码更新：

```sh
docker compose build --pull app
docker compose run --rm app php artisan migrate --force
docker compose up -d --force-recreate app
```

首次安装通过浏览器完成。**不要在已有数据库上重新运行 `lsky:install`**：它会调用 `migrate:fresh` 重建表。镜像更新使用常规 `migrate`，不会自动运行安装器。Docker 部署使用镜像更新，后台在线增量更新的代码变更不会持久保存到新容器。

## 本地开发

```sh
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
```

开发镜像默认使用 UID/GID 1000 的 Web 用户。Linux 宿主机用户 ID 不同，可在构建时传入 `LOCAL_UID=$(id -u) LOCAL_GID=$(id -g)`，避免绑定目录权限不匹配。开发环境绑定当前源码，Composer 依赖放在单独的 `lsky-vendor` 卷中，运行数据仍使用 `lsky-data`。开发和生产部署建议使用不同的 Compose 项目名（`-p`），避免共用数据。修改 Composer 锁文件后，重新构建开发镜像并更新依赖卷：

```sh
docker compose -f compose.yaml -f compose.dev.yaml run --rm app composer install
```

Artisan 和 Composer 均在 PHP 8.5.12 容器中运行：

```sh
docker compose -f compose.yaml -f compose.dev.yaml exec app php artisan --version
docker compose -f compose.yaml -f compose.dev.yaml exec app composer check-platform-reqs
```

PHP 原生安装也需要精确的 8.5.12 和相同扩展；`.php-version` 与 Composer 平台配置已固定版本。推荐使用上述容器以减少环境差异。

## 隔离测试

测试会迁移、清空测试数据库，只能使用专用测试服务。不要在生产容器或生产数据卷中执行 PHPUnit。

```sh
docker compose -p lsky-tests -f compose.test.yaml build
docker compose -p lsky-tests -f compose.test.yaml run --rm tests
TEST_DB_CONNECTION=mysql TEST_DB_DATABASE=lsky \
  docker compose -p lsky-tests -f compose.test.yaml run --rm tests
TEST_DB_CONNECTION=pgsql TEST_DB_HOST=postgres TEST_DB_PORT=5432 TEST_DB_DATABASE=lsky \
  docker compose -p lsky-tests -f compose.test.yaml run --rm tests
docker compose -p lsky-tests -f compose.test.yaml down --volumes
```

测试配置创建独立 MySQL、PostgreSQL、Redis、FTP、SFTP、WebDAV、SMTP 和 HTTP S3 协议服务，无宿主机端口映射，凭据仅用于这些一次性测试容器。

只运行不依赖外部测试服务的应用回归：

```sh
docker build --target development -t lsky-pro:dev-php8.5.12 .
docker run --rm lsky-pro:dev-php8.5.12 php vendor/bin/phpunit --exclude-group integration
```

S3/MinIO 适配器使用 Moto HTTP 协议服务验证传输和图片上传管线；这不能替代 AWS 或 MinIO 实例验收。云审核和远程更新的单元测试使用固定响应验证应用处理逻辑。OSS、COS、七牛、又拍云及商业审核服务的完整联调需要专用测试凭据，不能从本地测试通过推断厂商服务可用。

## 生产容器验收

```sh
docker build --target production -t lsky-pro:php8.5.12 .
python3 tests/docker/acceptance.py
```

脚本创建独立容器、随机本机端口和一次性数据卷，执行真实 HTTP 安装、上传、管理、API、迁移和容器重建验证，结束时清理自己创建的资源。仅使用 Python 标准库和 Docker CLI。

浏览器操作额外需要 Playwright 和 Chromium：

```sh
python3 -m pip install playwright==1.55.0
python3 -m playwright install --with-deps chromium
python3 tests/docker/acceptance.py --browser
```

验证非默认容器监听端口：`python3 tests/docker/acceptance.py --web-port 8097`。

测试结果及外部服务限制见 [验证报告](verification.zh-CN.md)。

## GitHub 镜像构建

[工作流](../.github/workflows/main.yml) 在 GitHub 执行：

- PR 执行容器测试和生产镜像构建，不发布镜像。
- 推送 `master`、`dev`、`v*` 标签或手动触发时，先测试，通过后发布到 `ghcr.io/<owner>/<repo>`。
- 发布 `linux/amd64`、`linux/arm64` 镜像，生成分支、提交和语义版本标签；默认分支提供 `latest`。
- 使用仓库自带 `GITHUB_TOKEN`，发布作业需要 Packages 写权限。首次发布后可在 GitHub Packages 设置镜像可见性。

本地仅检查工作流语法，并直接执行 Docker 构建和应用测试，不模拟执行 GitHub Actions。多架构构建与实际 GHCR 推送结果以 GitHub 工作流运行记录为准。
