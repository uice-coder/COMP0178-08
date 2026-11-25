<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../src/AuctionService.php';
session_start();

if (empty($_SESSION['userID'])) {
    http_response_code(403);
    exit('Sign in to view your auction notifications.');
}
$service = new AuctionService(connect_db());
$service->settleDue();
$notifications = $service->notifications((int)$_SESSION['userID']);
require __DIR__ . '/header.php';
?>
<main class="container mt-4">
  <h1>Auction notifications</h1>
  <?php if (!$notifications): ?><p>No notifications yet.</p><?php endif; ?>
  <ul>
    <?php foreach ($notifications as $notice): ?>
      <li>
        <a href="listing.php?item_id=<?= (int)$notice['auction_id'] ?>">
          <?= htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <small><?= htmlspecialchars($notice['created_at'], ENT_QUOTES, 'UTF-8') ?> UTC</small>
      </li>
    <?php endforeach; ?>
  </ul>
</main>
<?php require __DIR__ . '/footer.php'; ?>
