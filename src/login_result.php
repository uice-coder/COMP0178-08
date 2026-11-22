<?php

// TODO: Extract $_POST variables, check they're OK, and attempt to login.
// Notify user of success/failure and redirect/give navigation options.

// For now, I will just set session variables and redirect.

session_start();
require_once __DIR__."/db.php";

if($_SERVER["REQUEST_METHOD"]!=="POST"){
    header("Location:index.php");
    exit;
}

$email=trim($_POST["email"]??"");
$password=$_POST["password"]??"";

if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password===""){
    exit("Invalid email or password.");
}

try{
    $pdo=connect_db();

    $stmt=$pdo->prepare(
        "SELECT userID,firstName,lastName,passwordHash
         From `User`
         WHERE email=?");
    
    $stmt->execute([$email]);
    $user=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user||!password_verify($password, $user["passwordHash"])){
        exit("Invalid email or password.");
    }

    $stmt=$pdo->prepare(
        "SELECT userID FROM Buyer WHERE userID=?"
    );
    $stmt->execute([$user["userID"]]);

    $isBuyer=(bool)$stmt->fetch();

    $stmt=$pdo->prepare(
        "SELECT userID FROM Seller WHERE userID=?"
    );
    $stmt->execute([$user["userID"]]);

    $isSeller=(bool)$stmt->fetch();

    if(!$isBuyer && !$isSeller){
        exit("This account does not have a valid role.");
    }

    session_regenerate_id(true);

    $_SESSION["logged_in"]=true;
    $_SESSION["userID"]=$user["userID"];
    $_SESSION["username"]=$user["firstName"];
    $_SESSION["is_buyer"]=$isBuyer;
    $_SESSION["is_seller"]=$isSeller;

    $_SESSION["account_type"]=$isSeller?"seller":"buyer";

    header("refresh:5;url=index.php");
    echo'<div class="text-center">
            <h2>Login successful!</h2>
            <p>You will be redirected to the homepage in 5 seconds.</p>
            <a href="index.php">Go to homepage now</a>
          </div>';
    exit;

 }catch(PDOException $e){
    error_log($e->getMessage());
    http_response_code(500);
    echo"Login is temporarily unavailable. Please try again later.";
 }

?>