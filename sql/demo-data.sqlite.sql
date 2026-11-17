-- adding test rows after creating the matching schema in an empty database

-- using four test accounts with the demo password DemoPass!2026

INSERT INTO `User` (userID,email,firstName,lastName,passwordHash,createdAt) VALUES
 (1,'seller@example.test','Alex','R','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',datetime('now')),
 (2,'buyer@example.test','PixelFox','B','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',datetime('now')),
 (3,'rival@example.test','Tayor','S','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',datetime('now')),
 (4,'both@example.test','OakAndVinyl','C','$2y$12$Xcp59RvMBZgIHZifcazsrO/RvAsBkr.exYBhc1zIdDD7klcDaoCJC',datetime('now'));

INSERT INTO Buyer (userID) VALUES (2),(3),(4);

INSERT INTO Seller (userID) VALUES (1),(4);

INSERT INTO Category (categoryID,name,description) VALUES
 (1,'Books','Books and reading material.'),
 (2,'Electronics','Cameras and other electronics.'),
 (3,'Home & Garden','Furniture, lighting and household items.'),
 (4,'Sports','Sports and cycling equipment.'),
 (5,'Collectibles','Records, prints and collectibles.');

INSERT INTO Auction (auctionID,sellerID,categoryID,title,description,itemCondition,startingPrice,reservePrice,bidIncrement,startsAt,endsAt,status) VALUES
 (1,1,4,'City bicycle','A reliable everyday bicycle, recently serviced.','Used - good','50.00','65.00','1.00',datetime('now', '-86400 seconds'),datetime('now', '+172800 seconds'),'open'),
 (2,1,2,'Compact film camera','A compact camera with a working shutter and clean lens.','Used - good','25.00','30.00','1.00',datetime('now', '-86400 seconds'),datetime('now', '+259200 seconds'),'open'),
 (3,4,3,'Oak desk lamp','A warm solid-oak lamp for a reading corner.','Used - good','15.00',NULL,'1.00',datetime('now', '-86400 seconds'),datetime('now', '+86400 seconds'),'open'),
 (4,1,1,'Database book collection','Three introductory database books with clean pages.','Used - good','12.00','15.00','1.00',datetime('now', '-86400 seconds'),datetime('now', '+345600 seconds'),'open'),
 (5,4,5,'Jazz vinyl collection','Five records stored carefully in original sleeves.','Used - good','20.00','25.00','1.00',datetime('now', '-86400 seconds'),datetime('now', '+432000 seconds'),'open'),
 (6,1,3,'Completed demo: reading chair','A completed example with a recorded winning order.','Used - good','30.00','35.00','1.00',datetime('now', '-172800 seconds'),datetime('now', '-3600 seconds'),'sold'),
 (7,1,5,'Completed demo: antique print','A completed example that attracted no bids.','Used - good','40.00','50.00','1.00',datetime('now', '-172800 seconds'),datetime('now', '-3600 seconds'),'unsold');

INSERT INTO Bid (bidID,auctionID,buyerID,amount,placedAt) VALUES
 (1,1,2,'50.00',datetime('now', '-7200 seconds')),
 (2,1,3,'51.00',datetime('now', '-7000 seconds')),
 (3,2,3,'30.00',datetime('now', '-6800 seconds')),
 (4,3,2,'15.00',datetime('now', '-6600 seconds')),
 (5,6,2,'35.00',datetime('now', '-7200 seconds'));

INSERT INTO WatchlistEntry (buyerID,auctionID,watchedAt) VALUES
 (2,1,datetime('now', '-8000 seconds')),
 (2,2,datetime('now', '-8000 seconds'));

INSERT INTO SaleOrder (orderID,auctionID,winningBidID,status,createdAt) VALUES
 (1,6,5,'awaiting_payment',datetime('now', '-3600 seconds'));
