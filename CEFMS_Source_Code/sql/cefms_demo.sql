/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `cefms` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `cefms`;
DROP TABLE IF EXISTS `event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `event` (
  `eventId` int(11) NOT NULL AUTO_INCREMENT,
  `organizerId` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `category` enum('Music','Dance','Craft','Food','Religious','Multi-cultural') NOT NULL,
  `ticketPrice` decimal(8,2) NOT NULL,
  `totalSeats` int(11) NOT NULL,
  `availableSeats` int(11) NOT NULL,
  `status` enum('Pending','Published','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `createdDate` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`eventId`),
  KEY `fk_event_org` (`organizerId`),
  CONSTRAINT `fk_event_org` FOREIGN KEY (`organizerId`) REFERENCES `user` (`userId`),
  CONSTRAINT `chk_seats` CHECK (`availableSeats` >= 0 and `availableSeats` <= `totalSeats`),
  CONSTRAINT `chk_price` CHECK (`ticketPrice` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `event` WRITE;
/*!40000 ALTER TABLE `event` DISABLE KEYS */;
INSERT INTO `event` VALUES
(1,2,'Pesta Kebudayaan Johor','A three-day celebration of Johor heritage featuring Zapin dance, Kuda Kepang performances, traditional games and local street food from across the state.','Multi-cultural',35.00,300,300,'Published',0,'2026-11-02 01:34:27'),
(2,2,'Festival Tarian Tradisi','Dance troupes from Malay, Chinese, Indian and Orang Asli communities perform Tarian Inang, Lion Dance, Bharatanatyam and Sewang in one evening.','Dance',25.00,200,200,'Published',0,'2026-11-02 01:34:27'),
(3,3,'Pasar Seni Warisan','Hands-on craft market with songket weaving, batik painting and wood carving workshops led by master artisans.','Craft',15.00,150,150,'Published',0,'2026-11-02 01:34:27'),
(4,3,'Malam Muzik Rakyat','An evening of folk music: Dikir Barat, Gamelan, Sape and Kompang with live ensembles.','Music',40.00,250,250,'Published',0,'2026-11-02 01:34:27'),
(5,4,'Festival Makanan Warisan','Taste heritage dishes from every state - Laksa Johor, Nasi Kerabu, Assam Pedas and Kuih Tradisi.','Food',20.00,400,400,'Published',0,'2026-11-02 01:34:27'),
(6,4,'Perarakan Thaipusam Heritage Night','Cultural showcase and procession exhibition marking Thaipusam traditions at Batu Caves.','Religious',10.00,500,500,'Published',0,'2026-11-02 01:34:27'),
(7,2,'Pameran Wayang Kulit','Exhibition and live performance of Wayang Kulit Kelantan by Tok Dalang masters.','Multi-cultural',18.00,120,120,'Pending',0,'2026-11-02 01:34:27'),
(8,4,'Pesta Menuai Kaamatan','Harvest festival with Sumazau dance, traditional Kadazan-Dusun food and handicraft stalls.','Multi-cultural',22.00,300,296,'Completed',0,'2026-11-02 01:34:27'),
(9,3,'Karnival Kompang Remaja','Youth Kompang competition and showcase.','Music',12.00,180,180,'Cancelled',0,'2026-11-02 01:34:27');
/*!40000 ALTER TABLE `event` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment` (
  `paymentId` int(11) NOT NULL AUTO_INCREMENT,
  `reservationId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('Online Banking','Card','E-Wallet') NOT NULL,
  `transactionRef` varchar(40) NOT NULL,
  `paymentDate` datetime NOT NULL,
  `status` enum('Pending','Successful','Failed') NOT NULL,
  PRIMARY KEY (`paymentId`),
  KEY `fk_pay_res` (`reservationId`),
  CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservationId`) REFERENCES `reservation` (`reservationId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payment` WRITE;
/*!40000 ALTER TABLE `payment` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reservation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation` (
  `reservationId` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `eventId` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `totalAmount` decimal(10,2) NOT NULL,
  `reservedDate` datetime NOT NULL,
  `expiryDate` datetime NOT NULL,
  `ticketCode` varchar(20) DEFAULT NULL,
  `status` enum('Reserved','Paid','Cancelled','Expired') NOT NULL DEFAULT 'Reserved',
  PRIMARY KEY (`reservationId`),
  UNIQUE KEY `ticketCode` (`ticketCode`),
  KEY `fk_res_user` (`userId`),
  KEY `fk_res_event` (`eventId`),
  CONSTRAINT `fk_res_event` FOREIGN KEY (`eventId`) REFERENCES `event` (`eventId`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`userId`) REFERENCES `user` (`userId`),
  CONSTRAINT `chk_qty` CHECK (`quantity` between 1 and 10)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reservation` WRITE;
/*!40000 ALTER TABLE `reservation` DISABLE KEYS */;
INSERT INTO `reservation` VALUES
(1,6,8,3,66.00,'2026-10-10 09:00:00','2026-10-10 09:15:00','CEF-DEMO01','Paid'),
(2,7,8,1,22.00,'2026-10-11 09:00:00','2026-10-11 09:15:00','CEF-DEMO02','Paid');
/*!40000 ALTER TABLE `reservation` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule` (
  `scheduleId` int(11) NOT NULL AUTO_INCREMENT,
  `eventId` int(11) NOT NULL,
  `venue` varchar(120) NOT NULL,
  `startDateTime` datetime NOT NULL,
  `endDateTime` datetime NOT NULL,
  `status` enum('Scheduled','Rescheduled','Cancelled') NOT NULL DEFAULT 'Scheduled',
  PRIMARY KEY (`scheduleId`),
  KEY `fk_sched_event` (`eventId`),
  KEY `idx_sched_venue` (`venue`,`startDateTime`,`endDateTime`),
  CONSTRAINT `fk_sched_event` FOREIGN KEY (`eventId`) REFERENCES `event` (`eventId`) ON DELETE CASCADE,
  CONSTRAINT `chk_time` CHECK (`endDateTime` > `startDateTime`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `schedule` WRITE;
/*!40000 ALTER TABLE `schedule` DISABLE KEYS */;
INSERT INTO `schedule` VALUES
(1,1,'Dataran Bandaraya Johor Bahru','2026-11-14 10:00:00','2026-11-14 22:00:00','Scheduled'),
(2,2,'Panggung Sari, Istana Budaya, Kuala Lumpur','2026-11-21 20:00:00','2026-11-21 23:00:00','Scheduled'),
(3,3,'Central Market Annexe, Kuala Lumpur','2026-11-08 09:00:00','2026-11-08 18:00:00','Scheduled'),
(4,4,'Dewan Merdeka, Melaka','2026-12-05 19:30:00','2026-12-05 22:30:00','Scheduled'),
(5,5,'Jonker Street Night Market Square, Melaka','2026-11-28 17:00:00','2026-11-28 23:00:00','Scheduled'),
(6,6,'Batu Caves Cultural Hall, Selangor','2026-12-12 18:00:00','2026-12-12 21:00:00','Scheduled'),
(7,7,'Muzium Negara, Kuala Lumpur','2026-12-19 14:00:00','2026-12-19 17:00:00','Scheduled'),
(8,8,'Dewan Kompleks Sukan, Kota Kinabalu','2026-10-17 10:00:00','2026-10-17 20:00:00','Scheduled'),
(9,9,'Dewan Seri Pinang, Pulau Pinang','2026-10-24 10:00:00','2026-10-24 16:00:00','Cancelled');
/*!40000 ALTER TABLE `schedule` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `userId` int(11) NOT NULL AUTO_INCREMENT,
  `fullName` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `passwordHash` varchar(255) NOT NULL,
  `role` enum('Event Participant','Organizer','Event Manager') NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `createdDate` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`userId`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES
(1,'Encik Hashim Abu Bakar','manager@cefms.my','0123456001','$2y$10$v2wpHWQKJxhoD.mhovbZ7.l0muWuchchM4Y2.trMcLs9ih9h2o8xm','Event Manager','Active','2026-11-02 01:34:27'),
(2,'Siti Nur Aminah','siti@organizer.my','0123456002','$2y$10$VItDeb7nvR5GObDeXxkvy.E/1jH5Rs4K11TsAnGjWRDjZgJ/t/gsO','Organizer','Active','2026-11-02 01:34:27'),
(3,'Ahmad Daniel','daniel@organizer.my','0123456003','$2y$10$4dcTy8kXr.iiJ7gstNdK/eh47QvGQustFCGIK8DYTCjlMcmQ1dY8u','Organizer','Active','2026-11-02 01:34:27'),
(4,'Lim Wei Jian','lim@organizer.my','0123456004','$2y$10$9xiw1NFHvKVnwUN8WPAhL.IQ6fPkRmyPYQfqRAcf8so8NuAJrTK0C','Organizer','Active','2026-11-02 01:34:27'),
(5,'Raju Kumar','raju@organizer.my','0123456005','$2y$10$9OmjcPCmkFTP0fOeno6aauAh74iYQmcV23mSo1PoIwZbITKHfuSDO','Organizer','Inactive','2026-11-02 01:34:27'),
(6,'Aisyah Rahman','aisyah@mail.com','0123456006','$2y$10$OAQGs52EjcU8d9xZpZUp0.cTQYqaIZWDFCbnOwnjl0xfp/CJr8QxK','Event Participant','Active','2026-11-02 01:34:27'),
(7,'Farid Hakim','farid@mail.com','0123456007','$2y$10$HRTbuwC4.EV8N6MMav./lOVcx9EkrrbRaknbNy1ODPK7BMbvvBi52','Event Participant','Active','2026-11-02 01:34:27');
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

