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
