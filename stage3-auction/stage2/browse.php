<?php include_once("header.php")?>
<?php require("utilities.php")?>

<?php
  require_once __DIR__ . "/db.php";
  $pdo=connect_db();

  $cat_stmt=$pdo->query("SELECT categoryID, name FROM Category ORDER By name ASC");
  $categories=$cat_stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!isset($_GET['keyword'])) {
    // TODO: Define behavior if a keyword has not been specified.
      $keyword="";
  }
  else {
    $keyword = trim($_GET['keyword']);
  }

  if (!isset($_GET['cat'])) {
    // TODO: Define behavior if a category has not been specified.
    $category="all";
  }
  else {
    $category = $_GET['cat'];
  }
  
  if (!isset($_GET['order_by'])) {
    // TODO: Define behavior if an order_by value has not been specified.
    $ordering="pricelow";
  }
  else {
    $ordering = $_GET['order_by'];
  }
  
  if (!isset($_GET['page'])) {
    $curr_page = 1;
  }
  else {
    $curr_page = filter_var($_GET['page'], FILTER_VALIDATE_INT);

    if($curr_page===false || $curr_page<1){
      $curr_page=1;
    }
  }
?>

<div class="container">

<h2 class="my-3">Browse listings</h2>

<div id="searchSpecs">
<!-- When this form is submitted, this PHP page is what processes it.
     Search/sort specs are passed to this page through parameters in the URL
     (GET method of passing data to a page). -->
<form method="get" action="browse.php">
  <div class="row">
    <div class="col-md-5 pr-0">
      <div class="form-group">
        <label for="keyword" class="sr-only">Search keyword:</label>
	    <div class="input-group">
          <div class="input-group-prepend">
            <span class="input-group-text bg-transparent pr-0 text-muted">
              <i class="fa fa-search"></i>
            </span>
          </div>
          <input type="text" class="form-control border-left-0" id="keyword" name="keyword" placeholder="Search for anything"
                 value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>
    </div>
    <div class="col-md-3 pr-0">
      <div class="form-group">
        <label for="cat" class="sr-only">Search within:</label>
        <select class="form-control" id="cat" name="cat">
          <option value="all" <?php if($category==="all") echo "selected"; ?>>
            All categories
          </option>
          
          <?php foreach($categories as $cat): ?>
            <option value="<?php echo (int)$cat['categoryID'];?>"
              <?php if((string)$category===(string)$cat["categoryID"]) echo "selected"; ?>>
              <?php echo htmlspecialchars($cat['name'],ENT_QUOTES,'UTF-8');?>
            </option>
          <?php endforeach;?>
        </select>
      </div>
    </div>
    <div class="col-md-3 pr-0">
      <div class="form-inline">
        <label class="mx-2" for="order_by">Sort by:</label>
        <select class="form-control" id="order_by" name="order_by">
          <option value="pricelow" <?php if($ordering==="pricelow") echo "selected"; ?>>
            Price (low to high)
          </option>
          <option value="pricehigh" <?php if($ordering==="pricehigh") echo "selected"; ?>>
            Price (high to low)
          </option>
          <option value="date"<?php if($ordering==="date") echo "selected"; ?>>
            Soonest expiry
          </option>
        </select>
      </div>
    </div>
    <div class="col-md-1 px-0">
      <button type="submit" class="btn btn-primary">Search</button>
    </div>
  </div>
</form>
</div> <!-- end search specs bar -->


</div>

<?php
  /* TODO: Use above values to construct a query. Use this query to 
     retrieve data from the database. (If there is no form data entered,
     decide on appropriate default value/default query to make. */
  //Default: show auctions that are open and have not ended
  $where=" WHERE status='open' AND endsAt>CURRENT_TIMESTAMP";
  $params=[];

  if($keyword!==""){
    $where .=" AND (title LIKE :keyword1 OR description LIKE :keyword2)";
    $params["keyword1"]="%" . $keyword ."%";
    $params["keyword2"]="%" . $keyword ."%";
  }

  if($category!=="all"){
    $category_id=filter_var($category, FILTER_VALIDATE_INT);

    if($category_id!==false && $category_id>0){
      $where .=" AND categoryID =:category";
      $params["category"]=$category_id;
    }
    else{
      $where .=" AND 1 = 0";
    }
  }

  if($ordering==="pricehigh"){
    $sort= " ORDER BY CAST(current_price AS DECIMAL(10,2)) DESC, auctionID DESC";
  }
  elseif($ordering==="date"){
    $sort= " ORDER BY endsAt ASC, auctionID ASC";
  }
  else{
    $sort= " ORDER BY CAST(current_price AS DECIMAL(10,2)) ASC, auctionID ASC";
  }

  $count_sql="SELECT COUNT(*) FROM Auction" .$where;
  $count_stmt=$pdo->prepare($count_sql);
  $count_stmt->execute($params);

  /* For the purposes of pagination, it would also be helpful to know the
     total number of results that satisfy the above query */
  $num_results = (int)$count_stmt->fetchColumn(); // TODO: Calculate me for real
  $results_per_page = 10;
  $max_page = max(1, ceil($num_results / $results_per_page));

  $curr_page=min($curr_page,$max_page);
  $offset=($curr_page-1) * $results_per_page;

  $sql="SELECT Auction.*, COALESCE(b.highest_bid, startingPrice) AS current_price,
          COALESCE(b.bid_count, 0) AS bid_count
        FROM Auction LEFT JOIN (
          SELECT auctionID AS bid_auction_id, MAX(CAST(amount AS DECIMAL(10,2))) AS highest_bid,
                 COUNT(*) AS bid_count FROM Bid GROUP BY auctionID
        ) b ON b.bid_auction_id = Auction.auctionID" . $where . $sort;
  $sql.=" lIMIT " . (int)$results_per_page;
  $sql.=" OFFSET " . (int)$offset;

  $stmt=$pdo->prepare($sql);
  $stmt->execute($params);
?>

<div class="container mt-5">

<!-- TODO: If result set is empty, print an informative message. Otherwise... -->

<?php if($num_results==0): ?><p>No auctions found matching your search.</p>

<?php else: ?>
<ul class="list-group">

<!-- TODO: Use a while loop to print a list item for each auction listing
     retrieved from the query -->

<?php
  //display the auctions retrieved form the database
  while($auction=$stmt->fetch(PDO::FETCH_ASSOC)){
    $item_id=$auction["auctionID"];
    $title=$auction["title"];
    $description=$auction["description"];
    $current_price=$auction["current_price"];
    $num_bids=(int)$auction["bid_count"];
    $end_date=new DateTime($auction["endsAt"]);

    // This uses a function defined in utilities.php
    print_listing_li($item_id, $title, $description, $current_price, $num_bids, $end_date);
  }
?>

</ul>

<?php endif; ?>

<!-- Pagination for results listings -->
<nav aria-label="Search results pages" class="mt-5">
  <ul class="pagination justify-content-center">
  
<?php

  // Copy any currently-set GET variables to the URL.
  $querystring = http_build_query([
    "keyword"=>$keyword,
    "cat"=>$category,
    "order_by"=>$ordering]);
  
  $querystring .="&amp;";

  $high_page_boost = max(3 - $curr_page, 0);
  $low_page_boost = max(2 - ($max_page - $curr_page), 0);
  $low_page = max(1, $curr_page - 2 - $low_page_boost);
  $high_page = min($max_page, $curr_page + 2 + $high_page_boost);
  
  if ($curr_page != 1) {
    echo('
    <li class="page-item">
      <a class="page-link" href="browse.php?' . $querystring . 'page=' . ($curr_page - 1) . '" aria-label="Previous">
        <span aria-hidden="true"><i class="fa fa-arrow-left"></i></span>
        <span class="sr-only">Previous</span>
      </a>
    </li>');
  }
    
  for ($i = $low_page; $i <= $high_page; $i++) {
    if ($i == $curr_page) {
      // Highlight the link
      echo('
    <li class="page-item active">');
    }
    else {
      // Non-highlighted link
      echo('
    <li class="page-item">');
    }
    
    // Do this in any case
    echo('
      <a class="page-link" href="browse.php?' . $querystring . 'page=' . $i . '">' . $i . '</a>
    </li>');
  }
  
  if ($curr_page < $max_page) {
    echo('
    <li class="page-item">
      <a class="page-link" href="browse.php?' . $querystring . 'page=' . ($curr_page + 1) . '" aria-label="Next">
        <span aria-hidden="true"><i class="fa fa-arrow-right"></i></span>
        <span class="sr-only">Next</span>
      </a>
    </li>');
  }
?>

  </ul>
</nav>


</div>



<?php include_once("footer.php")?>