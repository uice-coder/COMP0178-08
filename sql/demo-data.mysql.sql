-- adding test rows after creating the matching schema in an empty database

-- using four test accounts with the demo password DemoPass!2026

INSERT INTO `User` (userID,email,firstName,lastName,passwordHash,createdAt) VALUES
 (1,'seller@example.test','Alex','R','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',UTC_TIMESTAMP()),
 (2,'buyer@example.test','PixelFox','B','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',UTC_TIMESTAMP()),
 (3,'rival@example.test','Tayor','S','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',UTC_TIMESTAMP()),
 (4,'both@example.test','OakAndVinyl','C','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',UTC_TIMESTAMP());

INSERT INTO Buyer (userID) VALUES (2),(3),(4);

INSERT INTO Seller (userID) VALUES (1),(4);

INSERT INTO Category (categoryID,name,description) VALUES
 (1,'Books','Books and reading material.'),
 (2,'Electronics','Cameras and other electronics.'),
 (3,'Home & Garden','Furniture, lighting and household items.'),
 (4,'Sports','Sports and cycling equipment.'),
 (5,'Collectibles','Records, prints and collectibles.');

INSERT INTO Auction (auctionID,sellerID,categoryID,title,description,itemCondition,startingPrice,reservePrice,bidIncrement,startsAt,endsAt,status) VALUES
 (1,1,4,'City bicycle','A reliable everyday bicycle, recently serviced.','Used - good','50.00','65.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -86400 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 172800 SECOND),'open'),
 (2,1,2,'Compact film camera','A compact camera with a working shutter and clean lens.','Used - good','25.00','30.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -86400 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 259200 SECOND),'open'),
 (3,4,3,'Oak desk lamp','A warm solid-oak lamp for a reading corner.','Used - good','15.00',NULL,'1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -86400 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 86400 SECOND),'open'),
 (4,1,1,'Database book collection','Three introductory database books with clean pages.','Used - good','12.00','15.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -86400 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 345600 SECOND),'open'),
 (5,4,5,'Jazz vinyl collection','Five records stored carefully in original sleeves.','Used - good','20.00','25.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -86400 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 432000 SECOND),'open'),
 (6,1,3,'Completed demo: reading chair','A completed example with a recorded winning order.','Used - good','30.00','35.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -172800 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL -3600 SECOND),'sold'),
 (7,1,5,'Completed demo: antique print','A completed example that attracted no bids.','Used - good','40.00','50.00','1.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -172800 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL -3600 SECOND),'unsold');

INSERT INTO Bid (bidID,auctionID,buyerID,amount,placedAt) VALUES
 (1,1,2,'50.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -7200 SECOND)),
 (2,1,3,'51.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -7000 SECOND)),
 (3,2,3,'30.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -6800 SECOND)),
 (4,3,2,'15.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -6600 SECOND)),
 (5,6,2,'35.00',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -7200 SECOND));

INSERT INTO WatchlistEntry (buyerID,auctionID,watchedAt) VALUES
 (2,1,DATE_ADD(UTC_TIMESTAMP(), INTERVAL -8000 SECOND)),
 (2,2,DATE_ADD(UTC_TIMESTAMP(), INTERVAL -8000 SECOND));

INSERT INTO SaleOrder (orderID,auctionID,winningBidID,status,createdAt) VALUES
 (1,6,5,'awaiting_payment',DATE_ADD(UTC_TIMESTAMP(), INTERVAL -3600 SECOND));
