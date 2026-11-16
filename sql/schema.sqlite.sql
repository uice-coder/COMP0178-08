-- using SQLite for local checks of the same tables and relationships
-- storing prices as text with two decimal places to avoid floating point storage
-- doing price calculations in PHP using whole pence and casting SQL values for sorting
PRAGMA foreign_keys=ON;
CREATE TABLE "User" (userID INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255) NOT NULL UNIQUE COLLATE NOCASE, firstName VARCHAR(100) NOT NULL, lastName VARCHAR(100) NOT NULL, passwordHash VARCHAR(255) NOT NULL, createdAt TIMESTAMP NOT NULL);
CREATE TABLE Buyer (userID INTEGER PRIMARY KEY REFERENCES "User"(userID));
CREATE TABLE Seller (userID INTEGER PRIMARY KEY REFERENCES "User"(userID));
CREATE TABLE Category (categoryID INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL UNIQUE, description TEXT);
CREATE TABLE Auction (auctionID INTEGER PRIMARY KEY AUTOINCREMENT, sellerID INTEGER NOT NULL REFERENCES Seller(userID), categoryID INTEGER NOT NULL REFERENCES Category(categoryID), title VARCHAR(200) NOT NULL, description TEXT NOT NULL, itemCondition VARCHAR(100), startingPrice TEXT NOT NULL CHECK(CAST(startingPrice AS NUMERIC)>0), reservePrice TEXT CHECK(reservePrice IS NULL OR CAST(reservePrice AS NUMERIC)>=CAST(startingPrice AS NUMERIC)), bidIncrement TEXT NOT NULL CHECK(CAST(bidIncrement AS NUMERIC)>0), startsAt TIMESTAMP NOT NULL, endsAt TIMESTAMP NOT NULL CHECK(endsAt>startsAt), status VARCHAR(30) NOT NULL DEFAULT 'open' CHECK(status IN ('open','sold','unsold','cancelled')));
CREATE INDEX auctions_end ON Auction(status,endsAt);
CREATE INDEX auctions_category ON Auction(categoryID,status);
CREATE TABLE Bid (bidID INTEGER PRIMARY KEY AUTOINCREMENT, auctionID INTEGER NOT NULL REFERENCES Auction(auctionID), buyerID INTEGER NOT NULL REFERENCES Buyer(userID), amount TEXT NOT NULL CHECK(CAST(amount AS NUMERIC)>0), placedAt TIMESTAMP NOT NULL, UNIQUE(auctionID,bidID), UNIQUE(auctionID,amount));
CREATE INDEX bids_buyer ON Bid(buyerID,auctionID);
CREATE TABLE WatchlistEntry (buyerID INTEGER NOT NULL REFERENCES Buyer(userID), auctionID INTEGER NOT NULL REFERENCES Auction(auctionID), watchedAt TIMESTAMP NOT NULL, PRIMARY KEY(buyerID,auctionID));
CREATE TABLE SaleOrder (orderID INTEGER PRIMARY KEY AUTOINCREMENT, auctionID INTEGER NOT NULL UNIQUE REFERENCES Auction(auctionID), winningBidID INTEGER NOT NULL UNIQUE, status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment' CHECK(status IN ('awaiting_payment','completed')), createdAt TIMESTAMP NOT NULL, FOREIGN KEY(auctionID,winningBidID) REFERENCES Bid(auctionID,bidID));
-- adding notifications and queued emails outside the eight core tables
CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES "User"(userID),auction_id INTEGER REFERENCES Auction(auctionID),kind TEXT NOT NULL,message TEXT NOT NULL,read_at TEXT,created_at TEXT NOT NULL);
CREATE INDEX notifications_user ON notifications(user_id,created_at);
CREATE TABLE email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,notification_id INTEGER NOT NULL UNIQUE REFERENCES notifications(id),recipient TEXT NOT NULL,subject TEXT NOT NULL,body TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent')),attempts INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,sent_at TEXT);
CREATE INDEX outbox_status ON email_outbox(status,id);
