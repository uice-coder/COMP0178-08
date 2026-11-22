<?php

// TODO: Extract $_POST variables, check they're OK, and attempt to create
// an account. Notify user of success/failure and redirect/give navigation 
// options.
require_once __DIR__."/db.php";

if($_SERVER["REQUEST_METHOD"]!=="POST") {
    header("Location:register.php");
    exit;
}

$firstName=trim($_POST["firstName"]??"");
$lastName=trim($_POST["lastName"]??"");
$email=trim($_POST["email"]??"");
$password=$_POST["password"]??"";
$confirmation=$_POST["passwordConfirmation"]??"";
$accountType=$_POST["accountType"]??"";

if($firstName===""||$lastName===""){
    exit("First name and last name are required.");
}

if(strlen($firstName)>100||strlen($lastName)>100){
    exit("First name or last name is too long.");
}

if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>255){
    exit("Please enter a valid email address.");
}

if(strlen($password)<8){
    exit("Password must contain at least 8 characters.");
}

if($password!==$confirmation){
    exit("The passwords entered do not match. Please try again.");
}

if(!in_array($accountType,["buyer","seller"],true)){
    exit("Invalid account type.");
}

try{
    $pdo=connect_db();
   
    $stmt=$pdo->prepare("SELECT userID FROM `User` WHERE email=?");
    $stmt->execute([$email]);

    if($stmt->fetch()){
        exit("This email is already registered.");
    }

    $passwordHash=password_hash($password,PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $stmt=$pdo->prepare("INSERT INTO `User`
                         (email, firstName, lastName, passwordHash, createdAt)
                         VALUES(?,?,?,?,?)");

    $stmt->execute([$email,
                    $firstName,
                    $lastName,
                    $passwordHash,
                    gmdate("Y-m-d H:i:s")]);

    $userID=$pdo->lastInsertId();

    if($accountType==="buyer"){
        $stmt=$pdo->prepare("INSERT INTO Buyer(userID) VALUES (?)");}
    else{
        $stmt=$pdo->prepare("INSERT INTO Seller(userID) VALUES (?)");}

    $stmt->execute([$userID]);

    $pdo->commit();

    echo"<h2>Registration successful!</h2>";
    echo'<a href="index.php">Return to homepage</a>';
}

catch(PDOException $e){
    if(isset($pdo) && $pdo->inTransaction()){
        $pdo->rollBack();
    }

    $mysqlErrorCode=$e->errorInfo[1]??null;

    if($mysqlErrorCode===1062){
        http_response_code(409);
        echo"An account with this email address already exists.";
    }else{
        error_log($e->getMessage());
        http_response_code(500);
        echo"Registration failed. Please try again.";
    }
}catch(Throwable $e){
    if(isset($pdo) && $pdo->inTransaction()){
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo"An unexpected error occurred. Please try again.";
}


?>