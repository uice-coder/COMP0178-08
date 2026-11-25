<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['userID']) || empty($_SESSION['is_buyer'])) {
    http_response_code(403);
    exit('Sign in as a buyer to view your bids.');
}

$pdo = connect_db();
$stmt = $pdo->prepare(
    'SELECT a.auctionID, a.title, b.amount, b.placedAt
     FROM Bid b JOIN Auction a ON a.auctionID = b.auctionID
     WHERE b.buyerID = ?
     ORDER BY b.placedAt DESC, b.bidID DESC'
);
$stmt->execute([(int)$_SESSION['userID']]);
$bids = $stmt->fetchAll();
require __DIR__ . '/header.php';
?>
<main class="container mt-4">
  <h1>My bids</h1>
  <?php if (!$bids): ?><p>You have not placed any bids yet.</p><?php endif; ?>
  <ul>
    <?php foreach ($bids as $bid): ?>
      <li>
        <a href="listing.php?item_id=<?= (int)$bid['auctionID'] ?>">
          <?= htmlspecialchars($bid['title'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        £<?= number_format((float)$bid['amount'], 2) ?>
        <small><?= htmlspecialchars($bid['placedAt'], ENT_QUOTES, 'UTF-8') ?> UTC</small>
      </li>
    <?php endforeach; ?>
  </ul>
</main>
<?php require __DIR__ . '/footer.php'; ?>
