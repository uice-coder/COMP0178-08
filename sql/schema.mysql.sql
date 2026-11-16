-- storing prices as decimal pounds and times in UTC
-- starting with an empty database so existing tables are not replaced
CREATE TABLE `User` (
 userID INTEGER AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(255) NOT NULL UNIQUE,
 firstName VARCHAR(100) NOT NULL,
 lastName VARCHAR(100) NOT NULL,
 passwordHash VARCHAR(255) NOT NULL,
 createdAt TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE Buyer (
 userID INTEGER PRIMARY KEY,
 FOREIGN KEY (userID) REFERENCES `User`(userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE Seller (
 userID INTEGER PRIMARY KEY,
 FOREIGN KEY (userID) REFERENCES `User`(userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE Category (
 categoryID INTEGER AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE Auction (
 auctionID INTEGER AUTO_INCREMENT PRIMARY KEY,
 sellerID INTEGER NOT NULL,
 categoryID INTEGER NOT NULL,
 title VARCHAR(200) NOT NULL,
 description TEXT NOT NULL,
 itemCondition VARCHAR(100),
 startingPrice DECIMAL(10,2) NOT NULL CHECK (startingPrice > 0),
 reservePrice DECIMAL(10,2),
 bidIncrement DECIMAL(10,2) NOT NULL CHECK (bidIncrement > 0),
 startsAt TIMESTAMP NOT NULL,
 endsAt TIMESTAMP NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'open' CHECK (status IN ('open','sold','unsold','cancelled')),
 FOREIGN KEY (sellerID) REFERENCES Seller(userID),
 FOREIGN KEY (categoryID) REFERENCES Category(categoryID),
 CHECK (reservePrice IS NULL OR reservePrice >= startingPrice),
 CHECK (endsAt > startsAt),
 INDEX auctions_end (status, endsAt),
 INDEX auctions_category (categoryID, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE Bid (
 bidID INTEGER AUTO_INCREMENT PRIMARY KEY,
 auctionID INTEGER NOT NULL,
 buyerID INTEGER NOT NULL,
 amount DECIMAL(10,2) NOT NULL CHECK (amount > 0),
 placedAt TIMESTAMP NOT NULL,
 FOREIGN KEY (auctionID) REFERENCES Auction(auctionID),
 FOREIGN KEY (buyerID) REFERENCES Buyer(userID),
 UNIQUE KEY bid_identity_auction (auctionID, bidID),
 UNIQUE KEY bid_amount_per_auction (auctionID, amount),
 INDEX bids_buyer (buyerID, auctionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE WatchlistEntry (
 buyerID INTEGER NOT NULL,
 auctionID INTEGER NOT NULL,
 watchedAt TIMESTAMP NOT NULL,
 PRIMARY KEY (buyerID, auctionID),
 FOREIGN KEY (buyerID) REFERENCES Buyer(userID),
 FOREIGN KEY (auctionID) REFERENCES Auction(auctionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE SaleOrder (
 orderID INTEGER AUTO_INCREMENT PRIMARY KEY,
 auctionID INTEGER NOT NULL UNIQUE,
 winningBidID INTEGER NOT NULL UNIQUE,
 status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment' CHECK (status IN ('awaiting_payment','completed')),
 createdAt TIMESTAMP NOT NULL,
 FOREIGN KEY (auctionID) REFERENCES Auction(auctionID),
 FOREIGN KEY (auctionID, winningBidID) REFERENCES Bid(auctionID, bidID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
