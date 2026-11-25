<?php 
if(session_status()===PHP_SESSION_NONE){
    session_start();
}

if(!isset($_SESSION["account_type"]) || $_SESSION["account_type"]!="seller" || !isset($_SESSION["userID"])){
    header("Location: browse.php");
    exit;
}

if($_SERVER["REQUEST_METHOD"]!=="POST"){
    header("Location: create_auction.php");
    exit;
}
// This function takes the form data and adds the new auction to the database.

/* TODO #1: Connect to MySQL database (perhaps by requiring a file that
            already does this). */
require_once __DIR__ . "/db.php";

try{
    $pdo=connect_db();
    $sellerID=$_SESSION["userID"];

/* TODO #2: Extract form data into variables. Because the form was a 'post'
            form, its data can be accessed via $POST['auctionTitle'], 
            $POST['auctionDetails'], etc. Perform checking on the data to
            make sure it can be inserted into the database. If there is an
            issue, give some semi-helpful feedback to user. */

    $title=trim($_POST["auctionTitle"]??"");
    $description=trim($_POST["auctionDetails"]??"");
    $itemCondition=trim($_POST["auctionCondition"]??"");
    $categoryID=$_POST["auctionCategory"]??"";
    $startingPrice=$_POST["auctionStartPrice"]??"";
    $bidIncrement=$_POST["auctionBidIncrement"]??"";
    $reservePrice=$_POST["auctionReservePrice"]??"";
    $endDate=$_POST["auctionEndDate"]??"";

    if($title===""||strlen($title)>200){
        exit("Please enter a valid auction title.");
    }
    if($description===""){
        exit("Auction details are required.");
    }
    if(strlen($itemCondition)>100){
        exit("Item condition is too long.");
    }
    if(!filter_var($categoryID, FILTER_VALIDATE_INT) || $categoryID<=0){
        exit("Please select a valid category.");
    }
    $stmt=$pdo->prepare("SELECT categoryID FROM Category WHERE categoryID=?");

    $stmt->execute([$categoryID]);

    if(!$stmt->fetch()){
        exit("The selected category does not exist.");
    }

    $validMoney=function($value){
        return is_string($value)
            && preg_match('/^\d+(\.\d{1,2})?$/', $value)
            && (float)$value>0
            && (float)$value<=99999999.99;
    };

    if(!$validMoney($startingPrice)){
        exit("Please enter a valid starting price.");
    }
    if(!$validMoney($bidIncrement)){
        exit("Please enter a valid bid increment.");
    }
    if($reservePrice!=""){
        if(!$validMoney($reservePrice) || (float)$reservePrice<(float)$startingPrice){
            exit("Reserve price must be at least the starting price.");
        }
    }else{
        $reservePrice=null;
    }
    $end=DateTimeImmutable::createFromFormat(
        "!Y-m-d\TH:i",
        $endDate,
        new DateTimeZone("UTC")
    );

    if(!$end||$end->format("Y-m-d\TH:i")!==$endDate){
        exit("Please enter a valid end date.");
    }

    $start=new DateTimeImmutable("now", new DateTimeZone("UTC"));

    if($end<=$start){
        exit("End date must be later than the start date.");
    }

    $startsAt=$start->format("Y-m-d H:i:s");
    $endsAt=$end->format("Y-m-d H:i:s");

    $itemCondition=$itemCondition===""?null:$itemCondition;
/* TODO #3: If everything looks good, make the appropriate call to insert
            data into the database. */
    $stmt=$pdo->prepare(
        "INSERT INTO Auction
        (sellerID,categoryID,title,description,itemCondition,
        startingPrice,reservePrice, bidIncrement, startsAt, endsAt)
        VALUES(?,?,?,?,?,?,?,?,?,?)"
    );

    $stmt->execute([
        $sellerID, 
        $categoryID, 
        $title, 
        $description, 
        $itemCondition, 
        $startingPrice, 
        $reservePrice, 
        $bidIncrement, 
        $startsAt, 
        $endsAt
    ]);

    $auctionID=$pdo->lastInsertId();

}catch(PDOException $e){
    error_log($e->getMessage());
    http_response_code(500);
    exit("Auction creation failed. Please try again.");
}
include_once("header.php")?>

<div class="container my-5">

<?php
// If all is successful, let user know.
echo('<div class="text-center">Auction successfully created! <a href="listing.php?item_id=' .$auctionID .'">View your new listing.</a></div>');

?>

</div>


<?php include_once("footer.php")?>