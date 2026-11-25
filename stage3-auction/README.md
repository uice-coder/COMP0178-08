# COMP0178 Stage 2 出价与结算

以小组 Stage 2 的 PHP 页面为底稿，接入小组 Stage 3 压缩包中的 `AuctionService.php`、数据库表结构和集成用例。金额与拍卖状态检查由 PHP 服务执行。结算将拍卖状态、订单和通知写入同一个事务。出价详情每五秒刷新，保留正在输入的金额。

## 本地演示

使用 PHP 8.x，启用 PDO 和所选数据库的驱动。启用 `mbstring` 以运行完整服务和测试。只把 `stage2/` 配为网站目录。

```sh
php bin/setup.php --demo
php -S 127.0.0.1:8080 -t stage2
```

默认使用项目下的 SQLite 文件。按课程环境使用 MySQL 或 MariaDB 时，将 `.env.example` 复制为项目根目录的 `.env`，将连接设到一个独立的空演示数据库，然后运行上面的命令。已有小组数据库请先核对 `sql/schema.mysql.sql`，尤其是 `Bid`、`SaleOrder`、`notifications` 和 `email_outbox`。初始化脚本只用于空库。提交前请移除本地配置和演示数据库。

访问 `http://127.0.0.1:8080`。演示账号共用密码 `zimugao`。

- 买家 `buyer@qq.com` 和 `rival@qq.com` 可在两个独立会话中查看 **City bicycle**。初始最高价 £51，最低下一口 £52。
- 卖家 `seller@qq.com` 可在 **My Listings** 查看最高价与出价数。
- **Completed demo: reading chair** 展示成交和订单。**Completed demo: antique print** 展示无人出价流拍。到期样例可以在卖家的 **My Listings** 中打开。
- **Notifications** 显示各账号自己的站内通知。详情页和通知页访问时会触发到期结算。部署后如需无人访问时也结算，可定时运行 `php bin/close-auctions.php`。
- 外部邮件需要 SMTP 配置和显式执行 `php bin/send-emails.php --send`。下列测试使用模拟发送器。

已从老师提供的原始模板补回网页引用的 Bootstrap 和自定义样式。登录弹窗沿用模板对 jQuery 的 CDN 引用。关注和推荐属于额外功能，详情页移除了没有后端的关注按钮。

## 与课件的对应

| 课件 | 对应实现 |
|---|---|
| 1b SQL，第 40–46、58–60 页 | 浏览和卖家列表通过 `LEFT JOIN` 与 `GROUP BY` / `MAX` / `COUNT` 得到每场的当前价和出价数，包括尚未收到任何出价的拍卖。 |
| 1b SQL，第 73 页，及 Lab 3 的 InnoDB 小节 | MySQL 用事务和行锁协调出价与结算，确保最高价读取和写入按同一场拍卖串行执行。 |
| 2 Database Design | 买家与拍卖通过 `Bid` 关联。订单指向获胜出价，并通过外键和唯一约束保证获胜出价属于本场拍卖、每场最多一条订单。 |
| Lab 4，第 13–15 页 | 沿用 Stage 2 共用的 `db.php` 连接并由 PHP 处理表单，数据库操作使用 PDO 参数绑定。 |

## 验证

本次在 PHP 8.4.17 上实测，SQLite 集成 29/29、MySQL 8.0.44 集成 32/32、邮件队列 9/9，Stage 2 HTTP 测试通过。实时更新通过两个独立 HTTP 会话核对，浏览器画面和外部邮件送达未验证。

```sh
php tests/integration.php
php tests/mail_worker.php
python3 tests/http_stage2.py
```

HTTP 测试使用一个独立的临时 SQLite 文件和本机端口，覆盖有效出价、拒绝金额不足及非法输入、未登录、缺少提交令牌、到期后拒绝出价、另一个会话读取实时更新片段，以及买卖双方的结算通知。若 `php` 未加入命令路径，可以将 `PHP_BIN` 设为 MAMP 的 PHP 路径再运行 Python 测试。

MySQL 集成用例只能对专用的、可清空的 `auction_eight_test` 数据库运行。该测试会清空此库中的表。

```sh
TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=auction_eight_test;charset=utf8mb4' \
TEST_DB_USER='your_test_user' TEST_DB_PASS='your_test_password' php tests/integration.php
```
