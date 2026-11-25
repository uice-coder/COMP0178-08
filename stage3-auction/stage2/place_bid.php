<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../src/AuctionService.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Submit a bid through the auction page.');
}
if (empty($_SESSION['userID'])) {
    http_response_code(403);
    exit('Sign in as a buyer to bid.');
}
$token = $_POST['csrf'] ?? null;
$expected = $_SESSION['bid_csrf'] ?? null;
if (!is_string($token) || !is_string($expected) || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit('Refresh the auction page and try again.');
}
$id = filter_input(INPUT_POST, 'auction_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amount = $_POST['amount'] ?? null;
if (!$id || !is_string($amount)) {
    http_response_code(400);
    exit('Enter an amount and select a valid auction.');
}

try {
    $service = new AuctionService(connect_db());
    $service->placeBid((int)$_SESSION['userID'], $id, $amount);
    $_SESSION['bid_feedback'][$id] = 'Your bid was placed.';
} catch (DomainException $exception) {
    $_SESSION['bid_feedback'][$id] = $exception->getMessage();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('The bid could not be processed. Refresh the auction before retrying.');
}
header('Location: listing.php?item_id=' . $id, true, 303);
