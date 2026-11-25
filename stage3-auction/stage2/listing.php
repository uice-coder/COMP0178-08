<?php
  session_start();
  require_once __DIR__ . '/utilities.php';
  require_once __DIR__ . '/db.php';
  $pdo=connect_db();
  // Get info from the URL:
  $item_id = filter_input(INPUT_GET,'item_id',FILTER_VALIDATE_INT);
  
  if(!$item_id || $item_id<=0){
    http_response_code(400);
    exit("Invalid auction ID.");
  }

  require_once __DIR__ . '/../src/AuctionService.php';
  $service = new AuctionService($pdo);
  $service->settleDue();
  try {
    $auction = $service->auction($item_id);
  } catch (DomainException $exception) {
    http_response_code(404);
    exit('Auction not found.');
  }
  $title = $auction['title'];
  $description = $auction['description'];
  $current_price = $auction['current_cents'] / 100;
  $end_time = new DateTimeImmutable($auction['ends_at'], new DateTimeZone('UTC'));
  $status = $auction['status'];
  $nowSql = gmdate('Y-m-d H:i:s');
  $canBid = !empty($_SESSION['is_buyer'])
    && (int)$_SESSION['userID'] !== (int)$auction['seller_id']
    && $status === 'open' && $auction['starts_at'] <= $nowSql && $auction['ends_at'] > $nowSql;
  $refresh = ($_GET['refresh'] ?? null) === '1';
  header('Cache-Control: no-store');
  $feedback = $refresh ? null : ($_SESSION['bid_feedback'][$item_id] ?? null);
  if (!$refresh) {
    unset($_SESSION['bid_feedback'][$item_id]);
  }
  if ($canBid) {
    $_SESSION['bid_csrf'] ??= bin2hex(random_bytes(32));
  }

  // Calculate time to auction end:
  $now = new DateTime();
  
  if ($now < $end_time){
    $time_to_end = date_diff($now, $end_time);
    $time_remaining = ' (in ' . display_time_remaining($time_to_end) . ')';
  }
  
?>
<?php if (!$refresh): require __DIR__ . '/header.php'; ?>
<div class="container">
  <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if ($feedback !== null): ?>
    <p role="status"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

<div class="row"> <!-- Row #2 with auction description + bidding info -->
  <div class="col-sm-8"> <!-- Left col with item info -->

    <div class="itemDescription">
    <?php echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')); ?>
    </div>

  </div>

  <div class="col-sm-4"> <!-- Right col with bidding info -->

<?php endif; ?>
<section id="auction-update" data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
         data-refresh="listing.php?item_id=<?= $item_id ?>&amp;refresh=1">
<p><?= $auction['bid_count'] ? 'Current highest bid' : 'Starting price' ?>: £<?= number_format($current_price, 2) ?></p>
<p>Ends: <?= $end_time->format('j M H:i') ?> UTC<?= $time_remaining ?? '' ?></p>
<?php if ($auction['order']): ?>
  <p>Sold to <?= htmlspecialchars($auction['order']['winner_name'], ENT_QUOTES, 'UTF-8') ?>
    for £<?= number_format($auction['order']['winning_cents'] / 100, 2) ?>.</p>
<?php elseif ($status === 'unsold'): ?>
  <p>Auction ended without a qualifying winning bid.</p>
<?php elseif ($status === 'cancelled'): ?>
  <p>Auction cancelled.</p>
<?php elseif ($auction['starts_at'] > $nowSql): ?>
  <p>Bidding has not opened yet.</p>
<?php endif; ?>

<?php if ($canBid): ?>
  <?php $minimum = number_format($auction['min_bid_cents'] / 100, 2, '.', ''); ?>
  <form method="POST" action="place_bid.php">
    <input type="hidden" name="auction_id" value="<?= $item_id ?>">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['bid_csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <label for="bid">Your bid (£) · Minimum £<?= $minimum ?></label>
    <input type="number" class="form-control" id="bid" name="amount"
           min="<?= $minimum ?>" max="99999999.99" step="0.01" required>
    <button type="submit" class="btn btn-primary">Place bid</button>
  </form>
<?php endif; ?>
<h2>Bid history</h2>
<?php if (!$auction['bids']): ?>
  <p>No bids yet.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Bidder</th><th>Amount</th><th>Time (UTC)</th></tr></thead>
    <tbody>
      <?php foreach ($auction['bids'] as $bid): ?>
        <tr>
          <td><?= htmlspecialchars($bid['buyer_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>£<?= number_format($bid['amount_cents'] / 100, 2) ?></td>
          <td><?= htmlspecialchars($bid['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php if (!$refresh): ?>
</div>
</div>
</div>
<script src="auction-updates.js" defer></script>
<?php require __DIR__ . '/footer.php'; endif; ?>
