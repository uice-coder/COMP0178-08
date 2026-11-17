# Stage 1 - database foundation

This folder is the first cumulative development checkpoint for the planned **Member 1** allocation. It documents a proposed division of responsibilities; it does not establish who historically authored or contributed the work. Later stages retain these foundation files and add their own features.

Stage 1 contains the database connection/configuration, the exact final COMP0178-08 core ER model, both current schema scripts and optional relational test fixtures. The schema has eight core entities and the declared notification/outbox extension tables. It includes keys, subtype foreign keys and the same-auction winning-bid constraint. A web interface, account registration/login, listing management, bidding workflows and email delivery are introduced in later stages.

## Files

- `.env.example`, `.gitignore`, `.gitattributes`: configuration example and repository conventions.
- `src/db.php`: shared PDO connection and local environment loading.
- `sql/schema.mysql.sql`, `sql/schema.sqlite.sql`: matching canonical schemas.
- `sql/demo-data.mysql.sql`, `sql/demo-data.sqlite.sql`: optional synthetic records for relationship and query checks.
- `bin/setup-database.php`: self-contained schema/fixture installer; requires only `src/db.php`.
- `docs/final_ER_diagram.dbml`, `.png`, `.svg`: unchanged supplied final group model.
- `storage/.gitkeep`: local database/runtime directory placeholder.

The configuration, connection, schema and ER files make up the shared database foundation. The comments explain the main steps and relationships.

## Try the database foundation

For the portable SQLite demonstration, work in this folder with no `.env` file:

```sh
php bin/setup-database.php --demo
```

This creates `storage/auction.sqlite`. PHP needs PDO and `pdo_sqlite`. Omit `--demo` for the empty schema. Setup refuses any selected database containing existing user tables; it never drops or resets records.

For MySQL, create a separate empty database, copy `.env.example` to `.env`, set your own credentials and run the same command. PHP needs `pdo_mysql`; MySQL must support InnoDB and enforced CHECK constraints. The administrator creates the database and grants the setup account the necessary schema privileges. SQL dates and times are UTC. Do not point this checkpoint at a valuable existing database.

The fixtures contain four synthetic accounts, five categories, seven auctions, five bids, two watch entries and one winning order. Their IDs and references are explicit; dates are relative to fixture installation time. Five auctions are active, one is sold and one ended without bids. Account roles are Buyer, Seller or both. All four illustrative accounts use `DemoPass!2026` with password hashes stored in `User`:

| Email | Display name | Role |
|---|---|---|
| seller@example.test | Alex R | Seller |
| buyer@example.test | PixelFox B | Buyer |
| rival@example.test | Tayor S | Buyer |
| both@example.test | OakAndVinyl C | Buyer and seller |

These accounts allow later cumulative stages to demonstrate the same records. Stage 1 itself has no login page or HTTP server entry point.

A simple database check after setup is:

```sql
SELECT a.auctionID, a.title, a.status, COUNT(b.bidID) AS bidCount
FROM Auction a
LEFT JOIN Bid b ON b.auctionID = a.auctionID
GROUP BY a.auctionID, a.title, a.status
ORDER BY a.auctionID;
```

## Planned cumulative allocation

1. **Stage 1 / Member 1:** ER model, schema, configuration, connection and relational test data.
2. **Stage 2 / Member 2:** retain Stage 1 and add accounts, seller listing management, browsing/search/sorting and basic pages.
3. **Stage 3 / Member 3:** retain earlier stages and integrate bid processing, live bid history, auction outcomes, watchlists, notices/email, recommendations and application tests.
4. **Stage 4 / Member 4:** retain the complete website and add the design report, 3NF analysis, demonstration materials, evidence, final checks and submission preparation.

The stage sequence is a development handoff structure. The group must record actual individual contributions separately and accurately.
