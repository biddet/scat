--
-- Database: scat
-- ------------------------------------------------------
-- Server version	8.0.13

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
 SET NAMES utf8mb4 ;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `barcode`
--

DROP TABLE IF EXISTS `barcode`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `barcode` (
  `code` varchar(255) NOT NULL,
  `item` int(10) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`item`,`code`),
  KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `brand`
--

DROP TABLE IF EXISTS `brand`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `brand` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `slug` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cc_trace`
--

DROP TABLE IF EXISTS `cc_trace`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `cc_trace` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `traced` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `txn_id` int(10) unsigned DEFAULT NULL,
  `payment_id` int(10) unsigned DEFAULT NULL,
  `request` mediumblob,
  `response` mediumblob,
  `info` mediumblob,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `department`
--

DROP TABLE IF EXISTS `department`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `department` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL,
  `slug` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parent` (`parent_id`,`slug`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `giftcard`
--

DROP TABLE IF EXISTS `giftcard`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `giftcard` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pin` varchar(4) NOT NULL,
  `active` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `giftcard_txn`
--

DROP TABLE IF EXISTS `giftcard_txn`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `giftcard_txn` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int(10) unsigned NOT NULL,
  `entered` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `amount` decimal(9,2) DEFAULT NULL,
  `txn_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `txn_id` (`txn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `image`
--

DROP TABLE IF EXISTS `image`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `image` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `alt_text` mediumtext,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `ext` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `item`
--

DROP TABLE IF EXISTS `item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL DEFAULT '0',
  `code` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `variation` varchar(255) NOT NULL DEFAULT '',
  `brand` int(10) unsigned DEFAULT NULL,
  `retail_price` decimal(9,2) NOT NULL DEFAULT '0.00',
  `discount_type` enum('percentage','relative','fixed') DEFAULT NULL,
  `discount` decimal(9,2) DEFAULT NULL,
  `taxfree` tinyint(4) NOT NULL DEFAULT '0',
  `minimum_quantity` int(10) unsigned NOT NULL DEFAULT '1',
  `purchase_quantity` int(10) unsigned NOT NULL DEFAULT '1',
  `length` decimal(9,2) DEFAULT NULL,
  `width` decimal(9,2) DEFAULT NULL,
  `height` decimal(9,2) DEFAULT NULL,
  `weight` decimal(9,2) DEFAULT NULL,
  `color` varchar(6) DEFAULT NULL,
  `tic` char(5) NOT NULL DEFAULT '00000',
  `active` tinyint(1) NOT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `reviewed` tinyint(4) NOT NULL DEFAULT '0',
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `inventoried` datetime DEFAULT NULL,
  `prop65` tinyint(1) DEFAULT NULL,
  `oversized` tinyint(1) DEFAULT NULL,
  `hazmat` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `product` (`product_id`),
  KEY `brand` (`brand`),
  KEY `inventoried` (`inventoried`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loyalty`
--

DROP TABLE IF EXISTS `loyalty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `loyalty` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int(10) unsigned NOT NULL,
  `points` int(11) DEFAULT '0',
  `txn_id` int(10) unsigned NOT NULL DEFAULT '0',
  `processed` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loyalty_reward`
--

DROP TABLE IF EXISTS `loyalty_reward`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `loyalty_reward` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cost` int(11) NOT NULL DEFAULT '0',
  `item_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `note`
--

DROP TABLE IF EXISTS `note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `note` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('general','txn','person','item') NOT NULL DEFAULT 'general',
  `attach_id` int(10) unsigned DEFAULT NULL,
  `content` mediumtext,
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `person_id` int(10) unsigned NOT NULL DEFAULT '0',
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0',
  `public` tinyint(4) NOT NULL DEFAULT '0',
  `todo` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  KEY `parent_id` (`parent_id`,`id`),
  KEY `kind` (`kind`,`attach_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment`
--

DROP TABLE IF EXISTS `payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `payment` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `txn` int(10) unsigned NOT NULL,
  `method` enum('cash','change','credit','square','stripe','gift','check','dwolla','paypal','discount','withdrawal','bad','donation','internal') NOT NULL,
  `amount` decimal(9,3) NOT NULL,
  `cc_txn` varchar(32) DEFAULT NULL,
  `cc_refid` varchar(32) DEFAULT NULL,
  `cc_approval` varchar(30) DEFAULT NULL,
  `cc_lastfour` varchar(4) DEFAULT NULL,
  `cc_expire` varchar(4) DEFAULT NULL,
  `cc_type` varchar(32) DEFAULT NULL,
  `cc_sign` mediumblob,
  `cc_receipt` mediumblob,
  `discount` decimal(9,2) DEFAULT NULL,
  `processed` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `txn` (`txn`),
  KEY `processed` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `person`
--

DROP TABLE IF EXISTS `person`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `person` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('customer','employee','vendor') DEFAULT 'customer',
  `name` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `address` mediumtext,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `loyalty_number` varchar(32) DEFAULT NULL,
  `suppress_loyalty` tinyint(4) NOT NULL DEFAULT '0',
  `sms_ok` tinyint(1) DEFAULT '0',
  `email_ok` tinyint(1) DEFAULT '0',
  `birthday` date DEFAULT NULL,
  `notes` longtext,
  `tax_id` varchar(255) DEFAULT NULL,
  `payment_account_id` varchar(50) DEFAULT NULL,
  `vendor_rebate` decimal(9,2) NOT NULL DEFAULT '0.00',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loyalty_number` (`loyalty_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `price_override`
--

DROP TABLE IF EXISTS `price_override`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `price_override` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pattern` varchar(255) NOT NULL,
  `pattern_type` enum('like','rlike','product') NOT NULL DEFAULT 'like',
  `minimum_quantity` int(10) unsigned NOT NULL DEFAULT '1',
  `discount_type` enum('percentage','additional_percentage','relative','fixed') DEFAULT NULL,
  `discount` decimal(9,2) DEFAULT NULL,
  `expires` datetime DEFAULT NULL,
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pattern` (`pattern`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product`
--

DROP TABLE IF EXISTS `product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `product` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned DEFAULT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` mediumtext,
  `slug` varchar(120) NOT NULL,
  `image` varchar(255) NOT NULL DEFAULT '',
  `variation_style` enum('tabs','flat') DEFAULT 'flat',
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `active` tinyint(4) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `department` (`department_id`,`brand_id`,`slug`),
  KEY `name` (`name`),
  KEY `brand_id` (`brand_id`),
  FULLTEXT KEY `full` (`name`,`description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prop65_warning`
--

DROP TABLE IF EXISTS `prop65_warning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `prop65_warning` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `warning` mediumtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `redirect`
--

DROP TABLE IF EXISTS `redirect`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `redirect` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(512) NOT NULL,
  `dest` varchar(512) NOT NULL,
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `saved_search`
--

DROP TABLE IF EXISTS `saved_search`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `saved_search` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `search` varchar(255) NOT NULL,
  `last_checked` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `timeclock`
--

DROP TABLE IF EXISTS `timeclock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `timeclock` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `person` int(10) unsigned NOT NULL,
  `start` datetime NOT NULL,
  `end` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `timeclock_audit`
--

DROP TABLE IF EXISTS `timeclock_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `timeclock_audit` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry` int(10) unsigned DEFAULT NULL,
  `before_start` datetime DEFAULT NULL,
  `after_start` datetime DEFAULT NULL,
  `before_end` datetime DEFAULT NULL,
  `after_end` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `txn`
--

DROP TABLE IF EXISTS `txn`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `txn` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(50) DEFAULT NULL,
  `number` int(10) unsigned NOT NULL,
  `created` datetime NOT NULL,
  `filled` datetime DEFAULT NULL,
  `paid` datetime DEFAULT NULL,
  `type` enum('correction','vendor','customer','drawer') NOT NULL,
  `person` int(10) unsigned DEFAULT NULL,
  `tax_rate` decimal(9,3) NOT NULL,
  `returned_from` int(10) unsigned DEFAULT NULL,
  `no_rewards` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type` (`type`,`number`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `created` (`created`),
  KEY `person` (`person`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `txn_line`
--

DROP TABLE IF EXISTS `txn_line`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `txn_line` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `txn` int(10) unsigned NOT NULL,
  `line` int(10) unsigned DEFAULT NULL,
  `item` int(10) unsigned DEFAULT NULL,
  `ordered` int(11) NOT NULL,
  `allocated` int(11) NOT NULL DEFAULT '0',
  `override_name` varchar(255) DEFAULT NULL,
  `data` mediumblob,
  `retail_price` decimal(9,2) NOT NULL,
  `discount_type` enum('percentage','relative','fixed') DEFAULT NULL,
  `discount` decimal(9,2) DEFAULT NULL,
  `discount_manual` tinyint(4) NOT NULL DEFAULT '0',
  `taxfree` tinyint(4) NOT NULL DEFAULT '0',
  `tic` char(5) NOT NULL DEFAULT '00000',
  `tax` decimal(9,3) NOT NULL DEFAULT '0.000',
  PRIMARY KEY (`id`),
  KEY `txn` (`txn`,`line`),
  KEY `item` (`item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `txn_note`
--

DROP TABLE IF EXISTS `txn_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `txn_note` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `txn` int(10) unsigned NOT NULL,
  `entered` datetime NOT NULL,
  `content` mediumtext,
  `public` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_item`
--

DROP TABLE IF EXISTS `vendor_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
 SET character_set_client = utf8mb4 ;
CREATE TABLE `vendor_item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor` int(10) unsigned NOT NULL,
  `item` int(10) unsigned DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `vendor_sku` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `retail_price` decimal(9,2) NOT NULL,
  `net_price` decimal(9,2) NOT NULL,
  `promo_price` decimal(9,2) DEFAULT NULL,
  `promo_quantity` int(11) DEFAULT NULL,
  `barcode` varchar(20) DEFAULT NULL,
  `purchase_quantity` int(11) NOT NULL,
  `length` decimal(9,2) DEFAULT NULL,
  `width` decimal(9,2) DEFAULT NULL,
  `height` decimal(9,2) DEFAULT NULL,
  `weight` decimal(9,2) DEFAULT NULL,
  `special_order` tinyint(1) DEFAULT '0',
  `prop65` tinyint(4) DEFAULT NULL,
  `hazmat` tinyint(4) DEFAULT NULL,
  `oversized` tinyint(4) DEFAULT NULL,
  `added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_2` (`vendor`,`code`),
  KEY `item` (`item`),
  KEY `vendor` (`vendor`),
  KEY `code` (`code`),
  KEY `vendor_sku` (`vendor_sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'scat'
--
/*!50003 DROP FUNCTION IF EXISTS `ROUND_TO_EVEN` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `ROUND_TO_EVEN`(value decimal(32,16), places int) RETURNS decimal(32,16)
    DETERMINISTIC
BEGIN  RETURN IF(ABS(value - TRUNCATE(value, places)) * POWER(10, places + 1) = 5            AND NOT CONVERT(TRUNCATE(abs(value) * POWER(10, places), 0),                            UNSIGNED) % 2 = 1,            TRUNCATE(value, places), ROUND(value, places));
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `sale_price` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `sale_price`(retail_price decimal(9,2), type char(32), discount decimal(9,2)) RETURNS decimal(9,2)
    DETERMINISTIC
BEGIN   RETURN IF(type IS NOT NULL AND type != '',             CASE type             WHEN 'percentage' THEN               CAST(ROUND_TO_EVEN(retail_price * ((100 - discount) / 100), 2) AS DECIMAL(9,2))             WHEN 'relative' THEN               (retail_price - discount)             WHEN 'fixed' THEN               (discount)             END,             retail_price); END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `slug` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `slug`(val VARCHAR(255)) RETURNS varchar(255) CHARSET utf8
    DETERMINISTIC
RETURN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(REPLACE(LOWER(val), CHAR(0xC2A0), '-')), '&', 'and'), ' ', '-'), '"', ''), "'", ''), '/', '-'), ':', ''), '.', ''), '#', ''), '!', ''), '(', ''), ')', ''), '[', ''), ']', ''), ',', ''), '+', ''), '@', 'a'), '%', ''), '‘', ''), '’', ''), '“', ''), '”', ''), '®', ''), '°', '') ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `STRIP_NON_DIGIT` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `STRIP_NON_DIGIT`(input VARCHAR(255)) RETURNS varchar(255) CHARSET utf8mb4
    DETERMINISTIC
BEGIN
   DECLARE output   VARCHAR(255) DEFAULT '';
   DECLARE iterator INT          DEFAULT 1;
   WHILE iterator < (LENGTH(input) + 1) DO
      IF SUBSTRING(input, iterator, 1) IN ( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ) THEN
         SET output = CONCAT(output, SUBSTRING(input, iterator, 1));
      END IF;
      SET iterator = iterator + 1;
   END WHILE;
   RETURN output;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed 
