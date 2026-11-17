<?php
declare(strict_types=1);
// running this script from the terminal
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// loading the database connection code
require_once __DIR__.'/../src/db.php';
date_default_timezone_set('UTC');

$db=null;
try {
    // checking the command options
    foreach (array_slice($argv,1) as $argument) {
        if (!in_array($argument,['--demo','--help'],true)) throw new RuntimeException('Unknown option. Use --demo for illustrative records, or --help.');
    }
    // printing the instructions without creating any tables
    if (in_array('--help',$argv,true)) {
        echo "Create the Stage 1 database foundation in an empty selected database.\n";
        echo "Usage: php bin/setup-database.php [--demo]\n";
        echo "Configuration: .env or DB_DSN, DB_USER and DB_PASSWORD environment variables.\n";
        exit;
    }
    // connecting and checking which database type is being used
    $db=connect_db();
    $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    // checking for existing tables before creating anything
    $tables=$driver==='mysql'
        ? $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        : $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) throw new RuntimeException('Selected database already contains tables. No records were changed; choose a separate empty database.');
    // choosing the matching schema and sample data files
    $schema=__DIR__.'/../sql/schema.'.$driver.'.sql';
    $fixture=__DIR__.'/../sql/demo-data.'.$driver.'.sql';
    if (!is_file($schema) || !is_file($fixture)) throw new RuntimeException('Matching schema or fixture file is missing.');
    // SQLite can roll back table creation; MySQL commits it separately
    if ($driver==='sqlite') $db->beginTransaction();
    $db->exec(file_get_contents($schema));
    if ($driver==='mysql') $db->beginTransaction();
    // loading the sample rows when --demo is used
    if (in_array('--demo',$argv,true)) {
        $db->exec(file_get_contents($fixture));
        // checking that each sample user has at least one role
        $unassigned=(int)$db->query('SELECT COUNT(*) FROM `User` u LEFT JOIN Buyer b ON b.userID=u.userID LEFT JOIN Seller s ON s.userID=u.userID WHERE b.userID IS NULL AND s.userID IS NULL')->fetchColumn();
        if ($unassigned!==0) throw new RuntimeException('A demonstration account has no Buyer or Seller role.');
        // checking SQLite foreign keys after loading the rows
        if ($driver==='sqlite' && $db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Fixture relationship validation failed.');
    }
    // saving the changes after the checks pass
    $db->commit();
    echo 'Stage 1 database foundation created ('.$driver.").\n";
    if (in_array('--demo',$argv,true)) {
        echo "Loaded 4 accounts, 5 categories, 7 auctions, 5 bids, 2 watches and 1 sale order.\n";
        echo "Demo accounts: seller@example.test, buyer@example.test, rival@example.test, both@example.test\n";
        echo "Demo password: DemoPass!2026\n";
    }
    echo "Stage 1 contains the database foundation only. The web interface is introduced in Stage 2.\n";
} catch (Throwable $error) {
    // undoing changes that are still inside a transaction
    if ($db && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'Setup stopped: '.$error->getMessage()."\n");
    exit(1);
}
