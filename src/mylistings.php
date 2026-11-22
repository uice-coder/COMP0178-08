<?php 
  if(session_status()===PHP_SESSION_NONE){
    session_start();
  }
  if (!isset($_SESSION["userID"]) || !isset($_SESSION["account_type"])||$_SESSION["account_type"]!=="seller"){
    header("Location: browse.php");
    exit;
  }
?>

<?php include_once("header.php")?>
<?php require("utilities.php")?>

<?php require_once __DIR__ . "/db.php";
  $pdo=connect_db();
?>
<div class="container">

<h2 class="my-3">My listings</h2>

<?php
  // This page is for showing a user the auction listings they've made.
  // It will be pretty similar to browse.php, except there is no search bar.
  // This can be started after browse.php is working with a database.
  // Feel free to extract out useful functions from browse.php and put them in
  // the shared "utilities.php" where they can be shared by multiple files.
  
  
  // (checked above) TODO: Check user's credentials (cookie/session).
  
  // TODO: Perform a query to pull up their auctions.
  $seller_id=$_SESSION["userID"];

  $stmt=$pdo->prepare(
    "SELECT * FROM Auction
     WHERE sellerID = :sellerID
     ORDER BY startsAt DESC"
  );

  $stmt->execute(["sellerID"=>$seller_id]);
  
  // TODO: Loop through results and print them out as list items.
  $auction=$stmt->fetch(PDO::FETCH_ASSOC);

  if(!$auction){
    echo "<p>You have not created any auctions yet.</p>";
  }
  else{
    echo'<ul class="list-group">';

    do{
      $item_id=$auction["auctionID"];
      $title=$auction["title"];
      $description=$auction["description"];
      $current_price=$auction["startingPrice"];
      $num_bids=0;
      $end_date=new DateTime($auction["endsAt"]);

      print_listing_li(
        $item_id,
        $title,
        $description,
        $current_price,
        $num_bids,
        $end_date
      );
    } while($auction=$stmt->fetch(PDO::FETCH_ASSOC));

    echo'</ul>';
  }
?>

</div>

<?php include_once("footer.php")?>