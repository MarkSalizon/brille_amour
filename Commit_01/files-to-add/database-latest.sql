
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
DROP TABLE IF EXISTS `admin_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `admin_activity_log_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `admin_activity_log` WRITE;
/*!40000 ALTER TABLE `admin_activity_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_activity_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `admin_code` varchar(30) DEFAULT NULL,
  `position` varchar(100) DEFAULT 'Administrator',
  `permissions` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `admin_code` (`admin_code`),
  CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `catalog_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `catalog_migrations` (
  `version` varchar(64) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `catalog_migrations` WRITE;
/*!40000 ALTER TABLE `catalog_migrations` DISABLE KEYS */;
INSERT INTO `catalog_migrations` VALUES ('archive_customized_shirts_2026_09','2026-09-27 03:22:39'),('archive_document_lamination_pvc_2026_09','2026-09-27 03:22:39'),('combined_print_pricing_2026_09_25','2026-09-27 03:22:39'),('initial_catalog_seed_admin_managed_v1','2026-09-27 03:22:39'),('mugs_70_remove_posters_rename_prints_2026_09_25','2026-09-27 03:22:40'),('printing_shop_catalog_2026_09','2026-09-27 03:22:39'),('printing_shop_starter_prices_2026_09','2026-09-27 03:22:39');
/*!40000 ALTER TABLE `catalog_migrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (5,'Printing Products','Finished printed products such as tarpaulins, flyers, business cards, and invitations.','archived','2026-04-08 01:43:40'),(6,'Craft Products','Finished handmade and craft items such as scrapbooks, photo albums, and party decorations.','archived','2026-04-08 01:43:40'),(7,'Print & Craft Best Sellers','Top-selling customized items such as mugs, t-shirts, tote bags, and sticker packs.','archived','2026-04-08 01:43:40'),(8,'Event-based Products','Packages and items for special events such as birthdays, weddings, graduations, and baptisms.','archived','2026-04-08 01:43:40'),(9,'Large Format & Signage','Tarpaulins, posters and rigid printed signs','active','2026-09-27 03:22:39'),(10,'Business & Promotional Prints','Business cards, brochures, flyers, stickers and invitations','active','2026-09-27 03:22:39'),(11,'Apparel & Custom Merchandise','Printed shirts and personalized merchandise','active','2026-09-27 03:22:39'),(12,'Photo & Document Services','Photo, identification and document printing services','active','2026-09-27 03:22:39');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_key` varchar(40) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_role` enum('admin','staff','customer','system','bot') NOT NULL DEFAULT 'staff',
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_thread` (`thread_key`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
INSERT INTO `chat_messages` VALUES (1,'user-7-9',NULL,7,'staff','ywa','2026-09-27 04:06:51'),(2,'order-57',57,0,'system','New order BA-057 - Brille amour - Customized Mugs x1','2026-09-27 04:30:54'),(3,'order-57',57,0,'system','BA-057 moved to In Production (Brille amour)','2026-09-27 04:35:54'),(4,'order-57',57,0,'system','BA-057 moved to Quality Check (Chris Ian Sedon)','2026-09-27 04:36:38'),(5,'order-57',57,0,'system','BA-057 moved to Ready (Chris Ian Sedon)','2026-09-27 04:37:04'),(6,'order-57',57,0,'system','BA-057 moved to Completed (Brille amour)','2026-09-27 04:37:16'),(7,'order-58',58,0,'system','New order BA-058 - Brille amour - Tarpaulin Printing x1','2026-09-27 04:41:48'),(8,'order-59',59,0,'system','New order BA-059 - Brille amour - Business Cards x1','2026-09-29 13:24:43'),(9,'order-59',59,0,'system','BA-059 moved to In Production (Brille amour)','2026-09-29 13:26:35'),(10,'order-59',59,0,'system','BA-059 moved to Quality Check (Chris Ian Sedon)','2026-09-29 13:27:04'),(11,'order-59',59,0,'system','BA-059 moved to Ready (Chris Ian Sedon)','2026-09-29 13:27:20'),(12,'order-59',59,0,'system','BA-059 moved to Completed (Brille amour)','2026-09-29 13:27:40'),(13,'order-60',60,0,'system','New order BA-060 - Brille amour - Customized Mugs x2','2026-09-29 13:37:05'),(14,'order-61',61,0,'system','New order BA-061 - Brille amour - Tarpaulin Printing x1','2026-09-29 13:37:10'),(15,'order-60',60,0,'system','BA-060 moved to In Production (Brille amour)','2026-09-29 13:37:53'),(16,'order-61',61,0,'system','BA-061 moved to In Production (Brille amour)','2026-09-29 13:37:57'),(17,'order-60',60,0,'system','BA-060 moved to Quality Check (Chris Ian Sedon)','2026-09-29 13:38:34'),(18,'order-61',61,0,'system','BA-061 moved to Quality Check (Chris Ian Sedon)','2026-09-29 13:38:51'),(19,'order-60',60,0,'system','BA-060 moved to Ready (Chris Ian Sedon)','2026-09-29 13:39:20'),(20,'order-60',60,0,'system','Delivery update: Out for Delivery','2026-09-29 13:39:29'),(21,'order-60',60,0,'system','Delivery update: Delivered','2026-09-29 13:39:54'),(22,'order-61',61,0,'system','BA-061 moved to Ready (Chris Ian Sedon)','2026-09-29 13:47:50'),(23,'order-61',61,0,'system','Delivery update: Out for Delivery','2026-09-29 13:48:02'),(24,'order-61',61,0,'system','Delivery update: Delivered','2026-09-29 13:48:12'),(25,'order-62',62,0,'system','New order BA-062 - Brille amour - Tarpaulin Printing x1','2026-09-29 14:00:27'),(26,'order-62',62,0,'system','BA-062 moved to In Production (Brille amour)','2026-09-29 14:01:17'),(27,'order-62',62,0,'system','BA-062 moved to Quality Check (Chris Ian Sedon)','2026-09-29 14:01:31'),(28,'order-62',62,0,'system','BA-062 moved to Ready (Chris Ian Sedon)','2026-09-29 14:01:47'),(29,'order-62',62,0,'system','Delivery update: Out for Delivery','2026-09-29 14:02:05'),(30,'order-62',62,0,'system','Delivery update: Delivered','2026-09-29 14:02:19'),(31,'order-63',63,0,'system','New order BA-063 - Brille amour - Business Cards x1','2026-09-29 14:14:13'),(32,'order-64',64,0,'system','New order BA-064 - Brille amour - Customized Mugs x1','2026-10-01 04:05:41'),(33,'order-64',64,-1000000001,'customer','hello admin, regarding sainyong products','2026-10-01 04:06:57'),(34,'order-64',64,0,'system','BA-064 moved to In Production (Brille amour)','2026-10-01 04:07:48'),(35,'order-64',64,0,'system','BA-064 moved to Quality Check (Chris Ian Sedon)','2026-10-01 04:09:20'),(36,'order-64',64,0,'system','BA-064 moved to Ready (Chris Ian Sedon)','2026-10-01 04:09:34'),(37,'order-64',64,0,'system','Delivery update: Out for Delivery','2026-10-01 04:10:14'),(38,'order-64',64,0,'system','Delivery update: Delivered','2026-10-01 04:13:07'),(39,'order-65',65,0,'system','New order BA-065 - Brille amour - Customized Mugs x1','2026-10-01 04:38:51'),(40,'order-66',66,0,'system','New order BA-066 - Brille amour - Customized Mugs x1','2026-10-01 04:51:26'),(41,'order-67',67,0,'system','New order BA-067 - Mark Louie Salizon - Tarpaulin Printing x1','2026-10-01 06:42:17'),(42,'order-68',68,0,'system','New order BA-068 - Mark Louie Salizon - Tarpaulin Printing x1','2026-10-01 06:43:07'),(43,'order-67',67,0,'system','BA-067 moved to In Production (Brille amour)','2026-10-01 06:43:58'),(44,'order-67',67,0,'system','BA-067 moved to Quality Check (Chris Ian Sedon)','2026-10-01 06:44:56'),(45,'order-67',67,0,'system','BA-067 moved to Ready (Chris Ian Sedon)','2026-10-01 06:47:01'),(46,'order-67',67,0,'system','BA-067 moved to Completed (Brille amour)','2026-10-01 06:47:29');
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `chat_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_reads` (
  `user_id` int(11) NOT NULL,
  `thread_key` varchar(40) NOT NULL,
  `last_read_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`thread_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_reads` WRITE;
/*!40000 ALTER TABLE `chat_reads` DISABLE KEYS */;
INSERT INTO `chat_reads` VALUES (-1000000001,'customer-1000000001','2026-10-07 22:39:06'),(-1000000001,'order-64','2026-10-01 12:09:35'),(7,'user-7-10','2026-10-05 23:52:22'),(7,'user-7-9','2026-10-06 00:12:01'),(9,'order-64','2026-10-01 12:07:23'),(9,'order-67','2026-10-05 23:25:52');
/*!40000 ALTER TABLE `chat_reads` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `chat_thread_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_thread_states` (
  `user_id` int(11) NOT NULL,
  `thread_key` varchar(40) NOT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`thread_key`),
  KEY `idx_chat_thread_states_user` (`user_id`,`is_archived`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `chat_thread_states` WRITE;
/*!40000 ALTER TABLE `chat_thread_states` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_thread_states` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `checkout_payment_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_payment_orders` (
  `payment_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`payment_id`,`order_id`),
  KEY `idx_checkout_payment_orders_order` (`order_id`),
  CONSTRAINT `checkout_payment_orders_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `checkout_payments` (`id`),
  CONSTRAINT `checkout_payment_orders_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `checkout_payment_orders` WRITE;
/*!40000 ALTER TABLE `checkout_payment_orders` DISABLE KEYS */;
INSERT INTO `checkout_payment_orders` VALUES (1,57,37.50),(2,58,30.00),(3,59,370.00),(4,60,180.00),(4,61,160.00),(5,62,200.00),(6,63,370.00),(7,64,70.00),(8,65,17.50),(9,66,17.50),(10,67,30.00),(11,68,30.00);
/*!40000 ALTER TABLE `checkout_payment_orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `checkout_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_number` varchar(30) NOT NULL,
  `payment_method` varchar(80) NOT NULL DEFAULT 'GCash',
  `payment_method_other` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `review_note` varchar(500) NOT NULL DEFAULT '',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_number` (`reference_number`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `checkout_payments` WRITE;
/*!40000 ALTER TABLE `checkout_payments` DISABLE KEYS */;
INSERT INTO `checkout_payments` VALUES (1,'PMe92e90454c6d4b000891d03c','GCash','','',37.50,'verified','',9,'2026-09-27 12:35:43','2026-09-27 04:30:54'),(2,'PMace017e4db59684854338f6e','GCash','','',30.00,'pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,NULL,'2026-09-27 04:41:48'),(3,'PM9b978fa2cd1c0d655c5a65bf','GCash','','',370.00,'verified','',9,'2026-09-29 21:26:24','2026-09-29 13:24:42'),(4,'PM084f3e7c267627e5edd2111a','GCash','','',340.00,'verified','',9,'2026-09-29 21:37:39','2026-09-29 13:37:05'),(5,'PM21a3df3ec8a7b379eabb4f57','GCash','','',200.00,'verified','',9,'2026-09-29 22:01:06','2026-09-29 14:00:27'),(6,'PM7274ed2b4cc107f8d381ea39','GCash','','',370.00,'pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,NULL,'2026-09-29 14:14:13'),(7,'PM02b1e0b614dcd862d839ba35','GCash','','',70.00,'verified','',9,'2026-10-01 12:07:32','2026-10-01 04:05:40'),(8,'PMce6a32c55c19eaa34d0c61bc','GCash','','',17.50,'pending','',NULL,NULL,'2026-10-01 04:38:51'),(9,'PM1de7424a602d13d0879c91d3','GCash','','',17.50,'pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,NULL,'2026-10-01 04:51:26'),(10,'PM89b240c7648080a6e74b6c6d','GCash','','',30.00,'verified','',9,'2026-10-01 14:43:45','2026-10-01 06:42:17'),(11,'PM4d046532b18982f264585264','GCash','','',30.00,'pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,NULL,'2026-10-01 06:43:07');
/*!40000 ALTER TABLE `checkout_payments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customer_email_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_email_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `event_key` varchar(190) NOT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_email_event` (`event_key`),
  KEY `idx_customer_email_order` (`order_id`),
  KEY `idx_customer_email_customer` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customer_email_log` WRITE;
/*!40000 ALTER TABLE `customer_email_log` DISABLE KEYS */;
INSERT INTO `customer_email_log` VALUES (1,1,57,'order-created-57','brilleamour2@gmail.com','Order received - BA-057','2026-09-27 04:31:00'),(2,1,57,'payment-reviewed-1-verified-57','brilleamour2@gmail.com','Payment verified - BA-057','2026-09-27 04:35:49'),(3,1,57,'stage-in_production-57','brilleamour2@gmail.com','Order update: In Production - BA-057','2026-09-27 04:35:54'),(4,1,57,'stage-quality_check-57','brilleamour2@gmail.com','Order update: Quality Check - BA-057','2026-09-27 04:36:38'),(5,1,57,'stage-ready-57','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-057','2026-09-27 04:37:04'),(6,1,57,'stage-completed-57','brilleamour2@gmail.com','Order update: Completed - BA-057','2026-09-27 04:37:16'),(7,1,58,'order-created-58','brilleamour2@gmail.com','Order received - BA-058','2026-09-27 04:41:54'),(8,1,59,'payment-reviewed-3-verified-59','brilleamour2@gmail.com','Payment verified - BA-059','2026-09-29 13:26:30'),(9,1,59,'stage-in_production-59','brilleamour2@gmail.com','Order update: In Production - BA-059','2026-09-29 13:26:35'),(10,1,59,'stage-quality_check-59','brilleamour2@gmail.com','Order update: Quality Check - BA-059','2026-09-29 13:27:04'),(11,1,59,'stage-ready-59','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-059','2026-09-29 13:27:20'),(12,1,59,'stage-completed-59','brilleamour2@gmail.com','Order update: Completed - BA-059','2026-09-29 13:27:40'),(13,1,60,'order-created-60','brilleamour2@gmail.com','Order received - BA-060','2026-09-29 13:37:10'),(14,1,61,'order-created-61','brilleamour2@gmail.com','Order received - BA-061','2026-09-29 13:37:15'),(15,1,60,'payment-reviewed-4-verified-60','brilleamour2@gmail.com','Payment verified - BA-060','2026-09-29 13:37:44'),(16,1,61,'payment-reviewed-4-verified-61','brilleamour2@gmail.com','Payment verified - BA-061','2026-09-29 13:37:48'),(17,1,60,'stage-in_production-60','brilleamour2@gmail.com','Order update: In Production - BA-060','2026-09-29 13:37:53'),(18,1,61,'stage-in_production-61','brilleamour2@gmail.com','Order update: In Production - BA-061','2026-09-29 13:37:57'),(19,1,60,'stage-quality_check-60','brilleamour2@gmail.com','Order update: Quality Check - BA-060','2026-09-29 13:38:34'),(20,1,61,'stage-quality_check-61','brilleamour2@gmail.com','Order update: Quality Check - BA-061','2026-09-29 13:38:51'),(21,1,60,'stage-ready-60','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-060','2026-09-29 13:39:20'),(22,1,60,'delivery-out_for_delivery-60','brilleamour2@gmail.com','Order update: Out for Delivery - BA-060','2026-09-29 13:39:33'),(23,1,60,'delivery-delivered-60','brilleamour2@gmail.com','Order update: Delivered - BA-060','2026-09-29 13:39:58'),(24,1,61,'stage-ready-61','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-061','2026-09-29 13:47:50'),(25,1,61,'delivery-out_for_delivery-61','brilleamour2@gmail.com','Order update: Out for Delivery - BA-061','2026-09-29 13:48:07'),(26,1,61,'delivery-delivered-61','brilleamour2@gmail.com','Order update: Delivered - BA-061','2026-09-29 13:48:18'),(27,1,62,'order-created-62','brilleamour2@gmail.com','Order received - BA-062','2026-09-29 14:00:34'),(28,1,62,'payment-reviewed-5-verified-62','brilleamour2@gmail.com','Payment verified - BA-062','2026-09-29 14:01:11'),(29,1,62,'stage-in_production-62','brilleamour2@gmail.com','Order update: In Production - BA-062','2026-09-29 14:01:17'),(30,1,62,'stage-quality_check-62','brilleamour2@gmail.com','Order update: Quality Check - BA-062','2026-09-29 14:01:31'),(31,1,62,'stage-ready-62','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-062','2026-09-29 14:01:47'),(32,1,62,'delivery-out_for_delivery-62','brilleamour2@gmail.com','Order update: Out for Delivery - BA-062','2026-09-29 14:02:11'),(33,1,62,'delivery-delivered-62','brilleamour2@gmail.com','Order update: Delivered - BA-062','2026-09-29 14:02:25'),(34,1,63,'order-created-63','brilleamour2@gmail.com','Order received - BA-063','2026-09-29 14:14:18'),(35,1,64,'order-created-64','brilleamour2@gmail.com','Order received - BA-064','2026-10-01 04:05:46'),(36,1,64,'payment-reviewed-7-verified-64','brilleamour2@gmail.com','Payment verified - BA-064','2026-10-01 04:07:38'),(37,1,64,'stage-in_production-64','brilleamour2@gmail.com','Order update: In Production - BA-064','2026-10-01 04:07:48'),(38,1,64,'stage-quality_check-64','brilleamour2@gmail.com','Order update: Quality Check - BA-064','2026-10-01 04:09:20'),(39,1,64,'stage-ready-64','brilleamour2@gmail.com','Order update: Ready for Dispatch - BA-064','2026-10-01 04:09:34'),(40,1,64,'delivery-out_for_delivery-64','brilleamour2@gmail.com','Order update: Out for Delivery - BA-064','2026-10-01 04:10:20'),(41,1,64,'delivery-delivered-64','brilleamour2@gmail.com','Order update: Delivered - BA-064','2026-10-01 04:13:12'),(42,1,66,'order-created-66','brilleamour2@gmail.com','Order received - BA-066','2026-10-01 04:51:32'),(43,3,67,'order-created-67','mark_salizon@sjp2cd.edu.ph','Order received - BA-067','2026-10-01 06:42:22'),(44,3,68,'order-created-68','mark_salizon@sjp2cd.edu.ph','Order received - BA-068','2026-10-01 06:43:13'),(45,3,67,'payment-reviewed-10-verified-67','mark_salizon@sjp2cd.edu.ph','Payment verified - BA-067','2026-10-01 06:43:52'),(46,3,67,'stage-in_production-67','mark_salizon@sjp2cd.edu.ph','Order update: In Production - BA-067','2026-10-01 06:43:58'),(47,3,67,'stage-quality_check-67','mark_salizon@sjp2cd.edu.ph','Order update: Quality Check - BA-067','2026-10-01 06:44:56'),(48,3,67,'stage-ready-67','mark_salizon@sjp2cd.edu.ph','Order update: Ready - BA-067','2026-10-01 06:47:01'),(49,3,67,'stage-completed-67','mark_salizon@sjp2cd.edu.ph','Order update: Completed - BA-067','2026-10-01 06:47:29');
/*!40000 ALTER TABLE `customer_email_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `source` varchar(50) DEFAULT 'Other',
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `password_hash` varchar(255) DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `google_sub` varchar(255) DEFAULT NULL,
  `google_avatar` varchar(500) DEFAULT NULL,
  `google_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `terms_version` varchar(32) DEFAULT NULL,
  `terms_accepted_at` datetime DEFAULT NULL,
  `privacy_version` varchar(32) DEFAULT NULL,
  `privacy_acknowledged_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customers_status` (`status`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_google_sub` (`google_sub`),
  KEY `idx_customers_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'Brille amour','+6399360840292','brilleamour2@gmail.com','medija village,sitio bula','Website Account','Customer account verified through Gmail code.','active',NULL,'2026-09-27 03:24:38','2026-10-07 14:26:35','$2y$10$zR8kAjr1P4uyF/qOZS9Y4e52zEf3jptke.OlmJ3TdOr10RjlgPIiu','2026-09-27 11:24:38','103873553507706260677','https://lh3.googleusercontent.com/a/ACg8ocLPpugg9nujbBAXORa9JY3qS-ywavjzsegMXwZuPV1bqCo9YQ=s96-c',1,'2026-10-07 22:26:35','2026-10-01','2026-10-01 12:04:30','2026-10-01','2026-10-01 12:04:30'),(2,'Chris ian Sedon',NULL,'chrisiansedon6@gmail.com',NULL,'Other','Customer account created through Google sign-in.','active',NULL,'2026-09-27 03:27:54','2026-10-02 01:36:11',NULL,NULL,'102644657439900169436','https://lh3.googleusercontent.com/a/ACg8ocIDaV59GVxq_3ZqpywhCrnGRRzrnRJQHbjF2SXLdnzcyZi9hF-F=s96-c',1,'2026-10-02 09:36:01','2026-10-01','2026-10-02 09:36:11','2026-10-01','2026-10-02 09:36:11'),(3,'Mark Louie Salizon','+639936084029','mark_salizon@sjp2cd.edu.ph','medija village,sitio bula','Other','Customer account created through Google sign-in.','active',NULL,'2026-10-01 06:14:46','2026-10-01 06:41:49',NULL,NULL,'102058160237051052882','https://lh3.googleusercontent.com/a/ACg8ocInVs67jWfMarkF51ste-M9POktjRN30h-N0Nc0Y45pZBk8BdKN=s96-c',1,'2026-10-01 14:14:46','2026-10-01','2026-10-01 14:14:54','2026-10-01','2026-10-01 14:14:54');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `delivery_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_tracking` (
  `order_id` int(11) NOT NULL,
  `status` enum('pending','ready_for_dispatch','out_for_delivery','delivered') NOT NULL DEFAULT 'pending',
  `proof_file` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `delivery_tracking` WRITE;
/*!40000 ALTER TABLE `delivery_tracking` DISABLE KEYS */;
INSERT INTO `delivery_tracking` VALUES (60,'delivered',NULL,'Customer delivery completed.',9,'2026-09-29 13:39:54'),(61,'delivered',NULL,'Customer delivery completed.',9,'2026-09-29 13:48:12'),(62,'delivered',NULL,'Customer delivery completed.',9,'2026-09-29 14:02:19'),(64,'delivered',NULL,'Customer delivery completed.',9,'2026-10-01 04:13:07');
/*!40000 ALTER TABLE `delivery_tracking` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `design_proofs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `design_proofs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `proof_file` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `status` enum('pending','approved','changes_requested') NOT NULL DEFAULT 'pending',
  `customer_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_design_order` (`order_id`),
  KEY `idx_design_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `design_proofs` WRITE;
/*!40000 ALTER TABLE `design_proofs` DISABLE KEYS */;
/*!40000 ALTER TABLE `design_proofs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `innovation_daily_summary_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `innovation_daily_summary_log` (
  `summary_date` date NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `result` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`summary_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `innovation_daily_summary_log` WRITE;
/*!40000 ALTER TABLE `innovation_daily_summary_log` DISABLE KEYS */;
INSERT INTO `innovation_daily_summary_log` VALUES ('2026-09-27','2026-09-27 11:27:29','Sent to 2 admin(s)'),('2026-09-29','2026-09-29 21:00:16','Sent to 2 admin(s)'),('2026-10-01','2026-10-01 12:01:23','Sent to 2 admin(s)'),('2026-10-05','2026-10-05 23:22:56','Sent to 2 admin(s)');
/*!40000 ALTER TABLE `innovation_daily_summary_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `material_group` varchar(70) NOT NULL DEFAULT '',
  `sku` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) DEFAULT 'pcs',
  `reorder_level` int(11) DEFAULT 10,
  `unit_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','archived') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=147 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
INSERT INTO `inventory` VALUES (77,5,'Tarpaulin / Banner','','PRT-001','Custom printed tarpaulins and banners for events, promotions, and signage.',20.00,'pcs',10,150.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(78,5,'Flyers','','PRT-002','Printed flyers for promotions, announcements, and advertisements.',195.00,'pcs',50,5.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(79,5,'Brochures','','PRT-003','Folded printed brochures for marketing and information distribution.',90.00,'pcs',50,20.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(80,5,'Posters','','PRT-004','Large format printed posters for events and promotions.',50.00,'pcs',20,80.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(81,5,'Business Cards','','PRT-005','Professional business cards with custom design and printing.',281.00,'pcs',100,3.50,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(82,5,'Invitations - Wedding','','PRT-006','Custom printed wedding invitations with personalized design.',50.00,'pcs',20,35.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(83,5,'Invitations - Birthday','','PRT-007','Custom printed birthday party invitations with personalized design.',50.00,'pcs',20,25.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(84,5,'Stickers','','PRT-008','Custom printed stickers in various sizes and finishes.',100.00,'pcs',50,10.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(85,5,'Labels','','PRT-009','Custom printed product or packaging labels.',95.00,'pcs',50,8.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(86,5,'IDs','','PRT-010','Printed identification cards for organizations, schools, and events.',50.00,'pcs',20,50.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(87,5,'Lanyards','','PRT-011','Custom printed or plain lanyards for ID holders.',50.00,'pcs',20,40.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(88,5,'Photocopy & Document Printing','','PRT-012','Black and white or color photocopying and document printing service.',100.00,'pcs',10,3.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(89,5,'Bookbinding / Thesis Printing','','PRT-013','Thesis, reports, and document bookbinding and printing service.',20.00,'pcs',5,250.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(90,6,'Scrapbook','','CRF-001','Handcrafted scrapbooks for memories, photos, and keepsakes.',15.00,'pcs',5,350.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(91,6,'Photo Album','','CRF-002','Handmade or customized photo albums for printing and preserving photos.',15.00,'pcs',5,300.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(92,6,'Handmade Greeting Cards','','CRF-003','Custom handmade greeting cards for birthdays, holidays, and special occasions.',30.00,'pcs',10,50.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(93,6,'Party Banners','','CRF-004','Decorative party banners for birthdays, celebrations, and events.',25.00,'pcs',10,120.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(94,6,'Party Cutouts','','CRF-005','Custom party cutout decorations for events and celebrations.',25.00,'pcs',10,80.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(95,7,'Customized Mug','','BES-001','Personalized mugs with custom printed designs, photos, or text.',40.00,'pcs',10,250.00,'active',NULL,'2026-04-08 01:43:40','2026-09-27 04:05:52'),(96,7,'T-Shirt Printing','','BES-002','Custom design printing on t-shirts using DTF, sublimation, or screen printing.',25.00,'pcs',10,350.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(97,7,'Tote Bag with Print','','BES-003','Canvas or non-woven tote bags with custom printed designs.',25.00,'pcs',10,150.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(98,7,'Ref Magnet / Souvenir Giveaway','','BES-004','Custom printed refrigerator magnets as souvenirs or giveaways.',50.00,'pcs',20,35.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(99,7,'Personalized Notebook / Journal','','BES-005','Custom cover notebooks and journals with personalized printing.',30.00,'pcs',10,180.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(100,7,'Photo Frame with Printed Picture','','BES-006','Photo frames paired with custom printed pictures or designs.',20.00,'pcs',10,200.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(101,7,'Sticker Pack - Aesthetic','','BES-007','Aesthetic-themed sticker packs for personal use, journaling, and decoration.',50.00,'pcs',20,49.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(102,7,'Sticker Pack - K-Pop','','BES-008','K-Pop themed sticker packs for fans and collectors.',50.00,'pcs',20,49.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(103,7,'Sticker Pack - Anime','','BES-009','Anime-themed sticker packs for fans and collectors.',50.00,'pcs',20,49.00,'active',NULL,'2026-04-08 01:43:40','2026-04-08 01:43:40'),(104,8,'Birthday Package','','EVT-001','Complete birthday package including tarpaulin, souvenirs, and invitations.',7.00,'set',5,999.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(105,8,'Wedding Package','','EVT-002','Complete wedding package including invites, giveaways, and signage.',10.00,'set',5,2500.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(106,8,'Graduation Tarpaulin','','EVT-003','Custom tarpaulin for graduation celebrations and ceremonies.',15.00,'pcs',5,200.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(107,8,'Graduation Medal','','EVT-004','Custom medals for graduation recognition and awards.',30.00,'pcs',10,85.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(108,8,'Graduation Certificate','','EVT-005','Custom printed graduation certificates with personalized details.',30.00,'pcs',10,50.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(109,8,'Baby Shower Souvenir','','EVT-006','Custom souvenir items for baby shower events and celebrations.',30.00,'pcs',10,75.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(110,8,'Baptism Souvenir','','EVT-007','Custom souvenir items for baptism and christening events.',25.00,'pcs',10,75.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(111,8,'Customized Frame','','EVT-008','Personalized frames for events, milestones, and commemorations.',20.00,'pcs',5,180.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(112,8,'DIY Souvenir Kit','','EVT-009','Do-it-yourself souvenir kits for events and celebrations.',15.00,'set',5,350.00,'archived',NULL,'2026-04-08 01:43:40','2026-09-27 03:22:40'),(113,5,'Mugs','','PROD-M-3','Imported from existing order history for Mugs',0.00,'piece',0,0.00,'archived',NULL,'2026-04-12 16:25:39','2026-09-27 03:22:40'),(114,8,'Invitations','','PROD-I-9','Auto-created stock record for product monitoring.',0.00,'piece',0,0.00,'archived',NULL,'2026-04-12 16:25:39','2026-09-27 03:22:40'),(115,6,'Custom T-Shirts','','PROD-C-T-S-10','Auto-created stock record for product monitoring.',0.00,'piece',0,0.00,'archived',NULL,'2026-04-12 16:25:39','2026-09-27 03:22:40'),(116,NULL,'Bond Paper A4 (80 gsm)','Paper & Card Stock','PAPER-A4-80','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(117,NULL,'Bond Paper Letter (80 gsm)','Paper & Card Stock','PAPER-LTR-80','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(118,NULL,'Bond Paper Legal (80 gsm)','Paper & Card Stock','PAPER-LGL-80','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(119,NULL,'Bond Paper A3 (80 gsm)','Paper & Card Stock','PAPER-A3-80','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:25'),(120,NULL,'Card Stock A4 (200 gsm)','Paper & Card Stock','PAPER-A4-200','Print-shop material. Enter actual opening stock before accepting orders.',98.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-29 14:14:13'),(121,NULL,'Card Stock A3 (200 gsm)','Paper & Card Stock','PAPER-A3-200','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(122,NULL,'Glossy Photo Paper 4x6','Paper & Card Stock','PAPER-PHOTO-4X6','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(123,NULL,'Glossy Photo Paper A4','Paper & Card Stock','PAPER-PHOTO-A4','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(124,NULL,'Printable Sticker Paper A4','Paper & Card Stock','PAPER-STICKER-A4','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(125,NULL,'Sublimation Transfer Paper A4','Paper & Card Stock','PAPER-SUBLIMATION-A4','Print-shop material. Enter actual opening stock before accepting orders.',14.00,'sheet',10,140.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:25'),(126,NULL,'Printing Ink - Cyan','Inks & Toners','INK-C','Print-shop material. Enter actual opening stock before accepting orders.',93.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:43:07'),(127,NULL,'Printing Ink - Magenta','Inks & Toners','INK-M','Print-shop material. Enter actual opening stock before accepting orders.',93.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:43:07'),(128,NULL,'Printing Ink - Yellow','Inks & Toners','INK-Y','Print-shop material. Enter actual opening stock before accepting orders.',93.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:43:07'),(129,NULL,'Printing Ink - Black','Inks & Toners','INK-K','Print-shop material. Enter actual opening stock before accepting orders.',93.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:43:07'),(130,NULL,'Sublimation Ink - Cyan','Inks & Toners','INK-SUB-C','Print-shop material. Enter actual opening stock before accepting orders.',94.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:25'),(131,NULL,'Sublimation Ink - Magenta','Inks & Toners','INK-SUB-M','Print-shop material. Enter actual opening stock before accepting orders.',94.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:25'),(132,NULL,'Sublimation Ink - Yellow','Inks & Toners','INK-SUB-Y','Print-shop material. Enter actual opening stock before accepting orders.',14.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:25'),(133,NULL,'Sublimation Ink - Black','Inks & Toners','INK-SUB-K','Print-shop material. Enter actual opening stock before accepting orders.',94.00,'ml',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:25'),(134,NULL,'Printable Tarpaulin (1 sq m)','Large Format Materials','TARP-SQM','Print-shop material. Enter actual opening stock before accepting orders.',95.00,'sq m',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:43:07'),(135,NULL,'Printable Vinyl (1 sq m)','Large Format Materials','VINYL-SQM','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sq m',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(136,NULL,'Sintra Board 2 x 4 ft','Large Format Materials','SINTRA-2X4','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(137,NULL,'Lamination Film A4 (pair)','Lamination & ID','LAM-FILM-A4','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'pair',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(138,NULL,'Lamination Film ID Size (pair)','Lamination & ID','LAM-FILM-ID','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'pair',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(139,NULL,'Blank PVC ID Card','Lamination & ID','PVC-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',30.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:00:39'),(140,NULL,'Blank T-Shirt','Apparel & Souvenir Blanks','SHIRT-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',20.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:25:12'),(141,NULL,'Heat Transfer Film A4','Apparel & Souvenir Blanks','TRANSFER-A4','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'sheet',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:29:45'),(142,NULL,'Blank Sublimation Mug','Apparel & Souvenir Blanks','MUG-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',24.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 04:51:26'),(143,NULL,'Blank Acrylic Keychain','Apparel & Souvenir Blanks','KEYCHAIN-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',10.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-10-01 06:48:43'),(144,NULL,'Blank Ref Magnet','Apparel & Souvenir Blanks','MAGNET-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',100.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:07:08'),(145,NULL,'Blank Button Pin Set','Apparel & Souvenir Blanks','PIN-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',20.00,'set',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 04:00:14'),(146,NULL,'Blank Bookmark Card','Apparel & Souvenir Blanks','BOOKMARK-BLANK','Print-shop material. Enter actual opening stock before accepting orders.',20.00,'pc',10,0.00,'active',NULL,'2026-09-27 03:22:40','2026-09-27 03:58:51');
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory_batch_restocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_batch_restocks` (
  `batch_key` varchar(80) NOT NULL,
  `applied_by` int(11) NOT NULL,
  `item_count` int(11) NOT NULL DEFAULT 0,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`batch_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_batch_restocks` WRITE;
/*!40000 ALTER TABLE `inventory_batch_restocks` DISABLE KEYS */;
INSERT INTO `inventory_batch_restocks` VALUES ('low_stock_plus_100_20260927',9,21,'2026-09-27 04:29:45');
/*!40000 ALTER TABLE `inventory_batch_restocks` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `type` enum('in','out','adjustment') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=182 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES (63,81,'out',3.00,'','Stock out by staff',7,'2026-04-08 01:57:34'),(64,104,'out',3.00,'BA-20260411-4889','Sold via order BA-20260411-4889 (Birthday Package)',7,'2026-04-11 04:07:56'),(65,109,'in',5.00,'','Stock in by staff',7,'2026-04-11 07:32:20'),(66,85,'out',5.00,'BA-20260412-8412','Auto stock deduction for order',7,'2026-04-12 16:28:39'),(67,78,'out',5.00,'BA-20260412-6262','Auto stock deduction for order',8,'2026-04-12 16:35:16'),(68,79,'out',5.00,'BA-20260413-5106','Auto stock deduction for order',7,'2026-04-13 04:37:28'),(69,79,'out',5.00,'BA-20260413-2209','Auto stock deduction for order',7,'2026-04-13 04:37:36'),(70,81,'out',5.00,'BA-20260413-6596','Auto stock deduction for order',7,'2026-04-13 04:42:44'),(71,81,'out',1.00,'BA-20260413-2820','Auto stock deduction for order',7,'2026-04-13 05:14:31'),(72,81,'out',1.00,'BA-20260413-4896','Auto stock deduction for order',7,'2026-04-13 05:15:23'),(73,81,'out',1.00,'BA-20260413-1598','Auto stock deduction for order',7,'2026-04-13 05:15:58'),(74,81,'out',1.00,'BA-20260413-4213','Auto stock deduction for order',7,'2026-04-13 05:16:13'),(75,81,'out',1.00,'BA-20260413-8816','Auto stock deduction for order',7,'2026-04-13 05:16:16'),(76,81,'out',1.00,'BA-20260413-8318','Auto stock deduction for order',7,'2026-04-13 05:16:24'),(77,81,'out',5.00,'BA-20260413-9373','Auto stock deduction for order',7,'2026-04-13 05:30:15'),(78,143,'in',100.00,'','Manual stock in',9,'2026-09-27 03:29:12'),(79,146,'in',10.00,'','Manual stock in',9,'2026-09-27 03:33:49'),(80,146,'in',10.00,'','Manual stock in',9,'2026-09-27 03:58:51'),(81,145,'in',10.00,'','Manual stock in',9,'2026-09-27 03:59:01'),(82,145,'in',10.00,'','Manual stock in',9,'2026-09-27 04:00:14'),(83,139,'in',10.00,'','Manual stock in',9,'2026-09-27 04:00:25'),(84,139,'in',20.00,'','Manual stock in',9,'2026-09-27 04:00:39'),(85,95,'in',10.00,'','Manual restock by staff',7,'2026-09-27 04:05:52'),(86,144,'in',100.00,'','Manual restock by staff',7,'2026-09-27 04:07:08'),(87,143,'out',90.00,'','Manual stock out',9,'2026-09-27 04:18:47'),(88,142,'in',30.00,'','Manual stock in',9,'2026-09-27 04:24:55'),(89,140,'in',20.00,'','Manual stock in',9,'2026-09-27 04:25:12'),(90,143,'in',20.00,'','Manual stock in',9,'2026-09-27 04:25:22'),(91,143,'in',20.00,'','Manual stock in',9,'2026-09-27 04:25:29'),(92,125,'in',20.00,'','Manual stock in',9,'2026-09-27 04:26:25'),(93,132,'in',20.00,'','Manual stock in',9,'2026-09-27 04:26:35'),(94,119,'in',100.00,'','Manual stock in',9,'2026-09-27 04:29:25'),(95,116,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(96,117,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(97,118,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(98,120,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(99,121,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(100,122,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(101,123,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(102,124,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(103,126,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(104,127,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(105,128,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(106,129,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(107,130,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(108,131,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(109,133,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(110,134,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(111,135,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(112,136,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(113,137,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(114,138,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(115,141,'in',100.00,'RESTOCK-LOW-100-20260927','Administrator stock adjustment: +100 in the inventory item unit',9,'2026-09-27 04:29:45'),(116,125,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(117,130,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(118,131,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(119,132,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(120,133,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(121,142,'out',1.00,'BA-057','Automatic raw-material allocation for order',8,'2026-09-27 04:30:54'),(122,126,'out',1.00,'BA-058','Automatic raw-material allocation for order',8,'2026-09-27 04:41:48'),(123,127,'out',1.00,'BA-058','Automatic raw-material allocation for order',8,'2026-09-27 04:41:48'),(124,128,'out',1.00,'BA-058','Automatic raw-material allocation for order',8,'2026-09-27 04:41:48'),(125,129,'out',1.00,'BA-058','Automatic raw-material allocation for order',8,'2026-09-27 04:41:48'),(126,134,'out',1.00,'BA-058','Automatic raw-material allocation for order',8,'2026-09-27 04:41:48'),(127,120,'out',1.00,'BA-059','Automatic raw-material allocation for order',8,'2026-09-29 13:24:42'),(128,126,'out',1.00,'BA-059','Automatic raw-material allocation for order',8,'2026-09-29 13:24:42'),(129,127,'out',1.00,'BA-059','Automatic raw-material allocation for order',8,'2026-09-29 13:24:42'),(130,128,'out',1.00,'BA-059','Automatic raw-material allocation for order',8,'2026-09-29 13:24:42'),(131,129,'out',1.00,'BA-059','Automatic raw-material allocation for order',8,'2026-09-29 13:24:42'),(132,125,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(133,130,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(134,131,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(135,132,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(136,133,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(137,142,'out',2.00,'BA-060','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(138,126,'out',1.00,'BA-061','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(139,127,'out',1.00,'BA-061','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(140,128,'out',1.00,'BA-061','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(141,129,'out',1.00,'BA-061','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(142,134,'out',1.00,'BA-061','Automatic raw-material allocation for order',8,'2026-09-29 13:37:05'),(143,126,'out',1.00,'BA-062','Automatic raw-material allocation for order',8,'2026-09-29 14:00:27'),(144,127,'out',1.00,'BA-062','Automatic raw-material allocation for order',8,'2026-09-29 14:00:27'),(145,128,'out',1.00,'BA-062','Automatic raw-material allocation for order',8,'2026-09-29 14:00:27'),(146,129,'out',1.00,'BA-062','Automatic raw-material allocation for order',8,'2026-09-29 14:00:27'),(147,134,'out',1.00,'BA-062','Automatic raw-material allocation for order',8,'2026-09-29 14:00:27'),(148,120,'out',1.00,'BA-063','Automatic raw-material allocation for order',8,'2026-09-29 14:14:13'),(149,126,'out',1.00,'BA-063','Automatic raw-material allocation for order',8,'2026-09-29 14:14:13'),(150,127,'out',1.00,'BA-063','Automatic raw-material allocation for order',8,'2026-09-29 14:14:13'),(151,128,'out',1.00,'BA-063','Automatic raw-material allocation for order',8,'2026-09-29 14:14:13'),(152,129,'out',1.00,'BA-063','Automatic raw-material allocation for order',8,'2026-09-29 14:14:13'),(153,125,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(154,130,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(155,131,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(156,132,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(157,133,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(158,142,'out',1.00,'BA-064','Automatic raw-material allocation for order',8,'2026-10-01 04:05:40'),(159,125,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(160,130,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(161,131,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(162,132,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(163,133,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(164,142,'out',1.00,'BA-065','Automatic raw-material allocation for order',8,'2026-10-01 04:38:51'),(165,125,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:25'),(166,130,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:25'),(167,131,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:25'),(168,132,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:25'),(169,133,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:25'),(170,142,'out',1.00,'BA-066','Automatic raw-material allocation for order',8,'2026-10-01 04:51:26'),(171,126,'out',1.00,'BA-067','Automatic raw-material allocation for order',8,'2026-10-01 06:42:17'),(172,127,'out',1.00,'BA-067','Automatic raw-material allocation for order',8,'2026-10-01 06:42:17'),(173,128,'out',1.00,'BA-067','Automatic raw-material allocation for order',8,'2026-10-01 06:42:17'),(174,129,'out',1.00,'BA-067','Automatic raw-material allocation for order',8,'2026-10-01 06:42:17'),(175,134,'out',1.00,'BA-067','Automatic raw-material allocation for order',8,'2026-10-01 06:42:17'),(176,126,'out',1.00,'BA-068','Automatic raw-material allocation for order',8,'2026-10-01 06:43:07'),(177,127,'out',1.00,'BA-068','Automatic raw-material allocation for order',8,'2026-10-01 06:43:07'),(178,128,'out',1.00,'BA-068','Automatic raw-material allocation for order',8,'2026-10-01 06:43:07'),(179,129,'out',1.00,'BA-068','Automatic raw-material allocation for order',8,'2026-10-01 06:43:07'),(180,134,'out',1.00,'BA-068','Automatic raw-material allocation for order',8,'2026-10-01 06:43:07'),(181,143,'out',40.00,'','Manual stock out',9,'2026-10-01 06:48:43');
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `material_inventory_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_inventory_migrations` (
  `version` varchar(64) NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `material_inventory_migrations` WRITE;
/*!40000 ALTER TABLE `material_inventory_migrations` DISABLE KEYS */;
INSERT INTO `material_inventory_migrations` VALUES ('printshop_materials_v1');
/*!40000 ALTER TABLE `material_inventory_migrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `material_order_baseline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_order_baseline` (
  `order_id` int(11) NOT NULL,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `material_order_baseline` WRITE;
/*!40000 ALTER TABLE `material_order_baseline` DISABLE KEYS */;
INSERT INTO `material_order_baseline` VALUES (56);
/*!40000 ALTER TABLE `material_order_baseline` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'order',
  `title` varchar(190) NOT NULL,
  `body` varchar(255) DEFAULT NULL,
  `thread_key` varchar(40) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `priority` enum('normal','warning','urgent') NOT NULL DEFAULT 'normal',
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `unique_key` varchar(120) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notification_user_event` (`user_id`,`unique_key`),
  KEY `idx_notifications_unread` (`user_id`,`is_read`),
  KEY `idx_notifications_recent` (`user_id`,`created_at`),
  KEY `idx_notifications_filter` (`user_id`,`type`,`priority`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=2388 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,9,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20260927',0,0,'2026-09-27 04:39:48',NULL),(58,8,'inventory','Low stock: Blank Bookmark Card','Blank Bookmark Card has only 10 pc remaining.',NULL,NULL,'warning','inventory',146,'low-stock-146-20260927',0,0,'2026-09-27 03:33:49',NULL),(59,9,'inventory','Low stock: Blank Bookmark Card','Blank Bookmark Card has only 10 pc remaining.',NULL,NULL,'warning','inventory',146,'low-stock-146-20260927',0,0,'2026-09-27 03:33:49',NULL),(245,8,'inventory','Low stock: Blank Button Pin Set','Blank Button Pin Set has only 10 set remaining.',NULL,NULL,'warning','inventory',145,'low-stock-145-20260927',0,0,'2026-09-27 03:59:01',NULL),(246,9,'inventory','Low stock: Blank Button Pin Set','Blank Button Pin Set has only 10 set remaining.',NULL,NULL,'warning','inventory',145,'low-stock-145-20260927',0,0,'2026-09-27 03:59:01',NULL),(277,8,'inventory','Low stock: Blank PVC ID Card','Blank PVC ID Card has only 10 pc remaining.',NULL,NULL,'warning','inventory',139,'low-stock-139-20260927',0,0,'2026-09-27 04:00:25',NULL),(278,9,'inventory','Low stock: Blank PVC ID Card','Blank PVC ID Card has only 10 pc remaining.',NULL,NULL,'warning','inventory',139,'low-stock-139-20260927',0,0,'2026-09-27 04:00:25',NULL),(346,7,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20260927',0,0,'2026-09-27 04:39:47',NULL),(376,9,'chat','Chris Ian Sedon sent a message','Brille amour - ywa','user-7-9',NULL,'normal','message',NULL,NULL,0,0,'2026-09-27 04:06:51',NULL),(441,8,'inventory','Low stock: Blank Acrylic Keychain','Blank Acrylic Keychain has only 10 pc remaining.',NULL,NULL,'warning','inventory',143,'low-stock-143-20260927',0,0,'2026-09-27 04:18:47',NULL),(442,9,'inventory','Low stock: Blank Acrylic Keychain','Blank Acrylic Keychain has only 10 pc remaining.',NULL,NULL,'warning','inventory',143,'low-stock-143-20260927',0,0,'2026-09-27 04:18:47',NULL),(568,8,'order','New order BA-057','Brille amour - Customized Mugs x1','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:30:54',NULL),(569,9,'order','New order BA-057','Brille amour - Customized Mugs x1','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:30:54',NULL),(590,8,'production','BA-057 moved to In Production','Brille amour - from Pending','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:35:49',NULL),(591,-1000000001,'production','Order BA-057: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:35:49',NULL),(613,-1000000001,'production','Order BA-057: Production started','A staff member has accepted your order and begun production.',NULL,57,'normal','order',57,'stage-accepted-in_production-57',0,0,'2026-09-27 04:36:30',NULL),(615,8,'production','BA-057 moved to Quality Check','Chris Ian Sedon - from In Production','order-57',57,'warning','order',57,NULL,0,0,'2026-09-27 04:36:33',NULL),(616,9,'production','BA-057 moved to Quality Check','Chris Ian Sedon - from In Production','order-57',57,'warning','order',57,NULL,0,0,'2026-09-27 04:36:33',NULL),(617,-1000000001,'production','Order BA-057: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-57',57,'warning','order',57,NULL,0,0,'2026-09-27 04:36:33',NULL),(628,-1000000001,'production','Order BA-057: Quality check started','A staff member has accepted your order for quality inspection.',NULL,57,'normal','order',57,'stage-accepted-quality_check-57',0,0,'2026-09-27 04:36:55',NULL),(630,8,'production','BA-057 moved to Ready','Chris Ian Sedon - from Quality Check','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:36:59',NULL),(631,9,'production','BA-057 moved to Ready','Chris Ian Sedon - from Quality Check','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:36:59',NULL),(632,-1000000001,'production','Order BA-057: Ready','Your order passed Quality Check and is ready for dispatch.','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:36:59',NULL),(639,8,'production','BA-057 moved to Completed','Brille amour - from Ready','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:37:11',NULL),(640,-1000000001,'production','Order BA-057: Completed','Your order is completed. Your final bill is now available in My Orders.','order-57',57,'normal','order',57,NULL,0,0,'2026-09-27 04:37:11',NULL),(659,8,'order','New order BA-058','Brille amour - Tarpaulin Printing x1','order-58',58,'normal','order',58,NULL,0,0,'2026-09-27 04:41:48',NULL),(660,9,'order','New order BA-058','Brille amour - Tarpaulin Printing x1','order-58',58,'normal','order',58,NULL,0,0,'2026-09-27 04:41:48',NULL),(661,9,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20260929',0,0,'2026-09-29 15:17:10',NULL),(685,7,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20260929',0,0,'2026-09-29 15:17:02',NULL),(704,8,'access','Staff access request','Chris Ian Sedon - Production',NULL,NULL,'warning','access_request',10,'access-request-10',0,0,'2026-09-29 13:10:13',NULL),(705,9,'access','Staff access request','Chris Ian Sedon - Production',NULL,NULL,'warning','access_request',10,'access-request-10',0,0,'2026-09-29 13:10:13',NULL),(732,8,'order','New order BA-059','Brille amour - Business Cards x1','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:24:43',NULL),(733,9,'order','New order BA-059','Brille amour - Business Cards x1','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:24:43',NULL),(735,9,'deadline','Order due soon: BA-059','This order is due today.','order-59',59,'warning','order',59,'due-59-20260929',0,0,'2026-09-29 13:27:34',NULL),(750,8,'production','BA-059 moved to In Production','Brille amour - from Pending','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:26:30',NULL),(751,10,'production','Order awaiting acceptance: BA-059','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-59',59,'normal','order',59,'stage-invite-in_production-59-10',0,0,'2026-09-29 13:26:30',NULL),(752,-1000000001,'production','Order BA-059: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:26:30',NULL),(767,-1000000001,'production','Order BA-059: Production started','A staff member has accepted your order and begun production.',NULL,59,'normal','order',59,'stage-accepted-in_production-59',0,0,'2026-09-29 13:26:55',NULL),(768,8,'production','BA-059 moved to Quality Check','Chris Ian Sedon - from In Production','order-59',59,'warning','order',59,NULL,0,0,'2026-09-29 13:26:58',NULL),(769,9,'production','BA-059 moved to Quality Check','Chris Ian Sedon - from In Production','order-59',59,'warning','order',59,NULL,0,0,'2026-09-29 13:26:58',NULL),(770,7,'production','Order awaiting acceptance: BA-059','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-59',59,'normal','order',59,'stage-invite-quality_check-59-7',0,0,'2026-09-29 13:26:58',NULL),(771,-1000000001,'production','Order BA-059: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-59',59,'warning','order',59,NULL,0,0,'2026-09-29 13:26:58',NULL),(776,-1000000001,'production','Order BA-059: Quality check started','A staff member has accepted your order for quality inspection.',NULL,59,'normal','order',59,'stage-accepted-quality_check-59',0,0,'2026-09-29 13:27:11',NULL),(777,8,'production','BA-059 moved to Ready','Chris Ian Sedon - from Quality Check','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:27:14',NULL),(778,9,'production','BA-059 moved to Ready','Chris Ian Sedon - from Quality Check','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:27:14',NULL),(779,-1000000001,'production','Order BA-059: Ready','Your order passed Quality Check and is ready for dispatch.','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:27:14',NULL),(794,8,'production','BA-059 moved to Completed','Brille amour - from Ready','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:27:35',NULL),(795,-1000000001,'production','Order BA-059: Completed','Your order is completed. Your final bill is now available in My Orders.','order-59',59,'normal','order',59,NULL,0,0,'2026-09-29 13:27:35',NULL),(818,8,'order','New order BA-060','Brille amour - Customized Mugs x2','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:37:05',NULL),(819,9,'order','New order BA-060','Brille amour - Customized Mugs x2','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:37:05',NULL),(820,8,'order','New order BA-061','Brille amour - Tarpaulin Printing x1','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:37:10',NULL),(821,9,'order','New order BA-061','Brille amour - Tarpaulin Printing x1','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:37:10',NULL),(823,9,'deadline','Order due soon: BA-060','This order is due tomorrow.','order-60',60,'warning','order',60,'due-60-20260929',0,0,'2026-09-29 13:39:53',NULL),(824,9,'deadline','Order due soon: BA-061','This order is due tomorrow.','order-61',61,'warning','order',61,'due-61-20260929',0,0,'2026-09-29 13:48:11',NULL),(834,8,'production','BA-060 moved to In Production','Brille amour - from Pending','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:37:48',NULL),(835,10,'production','Order awaiting acceptance: BA-060','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-60',60,'normal','order',60,'stage-invite-in_production-60-10',0,0,'2026-09-29 13:37:48',NULL),(836,-1000000001,'production','Order BA-060: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:37:48',NULL),(837,8,'production','BA-061 moved to In Production','Brille amour - from Pending','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:37:53',NULL),(838,10,'production','Order awaiting acceptance: BA-061','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-61',61,'normal','order',61,'stage-invite-in_production-61-10',0,0,'2026-09-29 13:37:53',NULL),(839,-1000000001,'production','Order BA-061: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:37:53',NULL),(882,-1000000001,'production','Order BA-060: Production started','A staff member has accepted your order and begun production.',NULL,60,'normal','order',60,'stage-accepted-in_production-60',0,0,'2026-09-29 13:38:24',NULL),(883,8,'production','BA-060 moved to Quality Check','Chris Ian Sedon - from In Production','order-60',60,'warning','order',60,NULL,0,0,'2026-09-29 13:38:29',NULL),(884,9,'production','BA-060 moved to Quality Check','Chris Ian Sedon - from In Production','order-60',60,'warning','order',60,NULL,0,0,'2026-09-29 13:38:29',NULL),(885,7,'production','Order awaiting acceptance: BA-060','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-60',60,'normal','order',60,'stage-invite-quality_check-60-7',0,0,'2026-09-29 13:38:29',NULL),(886,-1000000001,'production','Order BA-060: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-60',60,'warning','order',60,NULL,0,0,'2026-09-29 13:38:29',NULL),(895,-1000000001,'production','Order BA-061: Production started','A staff member has accepted your order and begun production.',NULL,61,'normal','order',61,'stage-accepted-in_production-61',0,0,'2026-09-29 13:38:44',NULL),(896,8,'production','BA-061 moved to Quality Check','Chris Ian Sedon - from In Production','order-61',61,'warning','order',61,NULL,0,0,'2026-09-29 13:38:46',NULL),(897,9,'production','BA-061 moved to Quality Check','Chris Ian Sedon - from In Production','order-61',61,'warning','order',61,NULL,0,0,'2026-09-29 13:38:46',NULL),(898,7,'production','Order awaiting acceptance: BA-061','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-61',61,'normal','order',61,'stage-invite-quality_check-61-7',0,0,'2026-09-29 13:38:46',NULL),(899,-1000000001,'production','Order BA-061: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-61',61,'warning','order',61,NULL,0,0,'2026-09-29 13:38:46',NULL),(917,-1000000001,'production','Order BA-060: Quality check started','A staff member has accepted your order for quality inspection.',NULL,60,'normal','order',60,'stage-accepted-quality_check-60',0,0,'2026-09-29 13:39:12',NULL),(919,8,'production','BA-060 moved to Ready','Chris Ian Sedon - from Quality Check','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:39:15',NULL),(920,9,'production','BA-060 moved to Ready','Chris Ian Sedon - from Quality Check','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:39:15',NULL),(921,-1000000001,'production','Order BA-060: Ready','Your order passed Quality Check and is ready for dispatch.','order-60',60,'normal','order',60,NULL,0,0,'2026-09-29 13:39:15',NULL),(935,-1000000001,'production','Order BA-060: Out for Delivery','Your Brille Amour order is now out for delivery.','order-60',60,'normal','order',60,'delivery-out_for_delivery-60',0,0,'2026-09-29 13:39:29',NULL),(954,-1000000001,'production','Order BA-060: Delivered','Your Brille Amour order has been delivered successfully.','order-60',60,'normal','order',60,'delivery-delivered-60',0,0,'2026-09-29 13:39:54',NULL),(964,-1000000001,'production','Order BA-061: Quality check started','A staff member has accepted your order for quality inspection.',NULL,61,'normal','order',61,'stage-accepted-quality_check-61',0,0,'2026-09-29 13:47:39',NULL),(967,8,'production','BA-061 moved to Ready','Chris Ian Sedon - from Quality Check','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:47:44',NULL),(968,9,'production','BA-061 moved to Ready','Chris Ian Sedon - from Quality Check','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:47:44',NULL),(969,-1000000001,'production','Order BA-061: Ready','Your order passed Quality Check and is ready for dispatch.','order-61',61,'normal','order',61,NULL,0,0,'2026-09-29 13:47:44',NULL),(980,-1000000001,'production','Order BA-061: Out for Delivery','Your Brille Amour order is now out for delivery.','order-61',61,'normal','order',61,'delivery-out_for_delivery-61',0,0,'2026-09-29 13:48:02',NULL),(989,-1000000001,'production','Order BA-061: Delivered','Your Brille Amour order has been delivered successfully.','order-61',61,'normal','order',61,'delivery-delivered-61',0,0,'2026-09-29 13:48:12',NULL),(1047,8,'order','New order BA-062','Brille amour - Tarpaulin Printing x1','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:00:27',NULL),(1048,9,'order','New order BA-062','Brille amour - Tarpaulin Printing x1','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:00:27',NULL),(1055,8,'production','BA-062 moved to In Production','Brille amour - from Pending','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:01:11',NULL),(1056,10,'production','Order awaiting acceptance: BA-062','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-62',62,'normal','order',62,'stage-invite-in_production-62-10',0,0,'2026-09-29 14:01:12',NULL),(1057,-1000000001,'production','Order BA-062: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:01:12',NULL),(1064,-1000000001,'production','Order BA-062: Production started','A staff member has accepted your order and begun production.',NULL,62,'normal','order',62,'stage-accepted-in_production-62',0,0,'2026-09-29 14:01:24',NULL),(1065,8,'production','BA-062 moved to Quality Check','Chris Ian Sedon - from In Production','order-62',62,'warning','order',62,NULL,0,0,'2026-09-29 14:01:26',NULL),(1066,9,'production','BA-062 moved to Quality Check','Chris Ian Sedon - from In Production','order-62',62,'warning','order',62,NULL,0,0,'2026-09-29 14:01:26',NULL),(1067,7,'production','Order awaiting acceptance: BA-062','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-62',62,'normal','order',62,'stage-invite-quality_check-62-7',0,0,'2026-09-29 14:01:26',NULL),(1068,-1000000001,'production','Order BA-062: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-62',62,'warning','order',62,NULL,0,0,'2026-09-29 14:01:26',NULL),(1073,-1000000001,'production','Order BA-062: Quality check started','A staff member has accepted your order for quality inspection.',NULL,62,'normal','order',62,'stage-accepted-quality_check-62',0,0,'2026-09-29 14:01:39',NULL),(1075,8,'production','BA-062 moved to Ready','Chris Ian Sedon - from Quality Check','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:01:42',NULL),(1076,9,'production','BA-062 moved to Ready','Chris Ian Sedon - from Quality Check','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:01:42',NULL),(1077,-1000000001,'production','Order BA-062: Ready','Your order passed Quality Check and is ready for dispatch.','order-62',62,'normal','order',62,NULL,0,0,'2026-09-29 14:01:42',NULL),(1086,-1000000001,'production','Order BA-062: Out for Delivery','Your Brille Amour order is now out for delivery.','order-62',62,'normal','order',62,'delivery-out_for_delivery-62',0,0,'2026-09-29 14:02:05',NULL),(1091,-1000000001,'production','Order BA-062: Delivered','Your Brille Amour order has been delivered successfully.','order-62',62,'normal','order',62,'delivery-delivered-62',0,0,'2026-09-29 14:02:19',NULL),(1100,8,'order','New order BA-063','Brille amour - Business Cards x1','order-63',63,'normal','order',63,NULL,0,0,'2026-09-29 14:14:13',NULL),(1101,9,'order','New order BA-063','Brille amour - Business Cards x1','order-63',63,'normal','order',63,NULL,0,0,'2026-09-29 14:14:13',NULL),(1160,9,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261001',0,0,'2026-10-01 15:46:05',NULL),(1174,7,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261001',0,0,'2026-10-01 15:56:27',NULL),(1207,8,'order','New order BA-064','Brille amour - Customized Mugs x1','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:05:41',NULL),(1208,9,'order','New order BA-064','Brille amour - Customized Mugs x1','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:05:41',NULL),(1209,8,'chat','Brille amour sent a message','BA-064 - hello admin, regarding sainyong products','order-64',64,'normal','message',NULL,NULL,0,0,'2026-10-01 04:06:57',NULL),(1210,9,'chat','Brille amour sent a message','BA-064 - hello admin, regarding sainyong products','order-64',64,'normal','message',NULL,NULL,0,0,'2026-10-01 04:06:57',NULL),(1229,8,'production','BA-064 moved to In Production','Brille amour - from Pending','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:07:40',NULL),(1230,10,'production','Order awaiting acceptance: BA-064','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-64',64,'normal','order',64,'stage-invite-in_production-64-10',0,0,'2026-10-01 04:07:40',NULL),(1231,-1000000001,'production','Order BA-064: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:07:40',NULL),(1246,-1000000001,'production','Order BA-064: Production started','A staff member has accepted your order and begun production.',NULL,64,'normal','order',64,'stage-accepted-in_production-64',0,0,'2026-10-01 04:08:44',NULL),(1249,8,'production','BA-064 moved to Quality Check','Chris Ian Sedon - from In Production','order-64',64,'warning','order',64,NULL,0,0,'2026-10-01 04:09:13',NULL),(1250,9,'production','BA-064 moved to Quality Check','Chris Ian Sedon - from In Production','order-64',64,'warning','order',64,NULL,0,0,'2026-10-01 04:09:13',NULL),(1251,7,'production','Order awaiting acceptance: BA-064','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-64',64,'normal','order',64,'stage-invite-quality_check-64-7',0,0,'2026-10-01 04:09:13',NULL),(1253,-1000000001,'production','Order BA-064: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-64',64,'warning','order',64,NULL,0,0,'2026-10-01 04:09:14',NULL),(1255,-1000000001,'production','Order BA-064: Quality check started','A staff member has accepted your order for quality inspection.',NULL,64,'normal','order',64,'stage-accepted-quality_check-64',0,0,'2026-10-01 04:09:20',NULL),(1259,8,'production','BA-064 moved to Ready','Chris Ian Sedon - from Quality Check','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:09:27',NULL),(1260,9,'production','BA-064 moved to Ready','Chris Ian Sedon - from Quality Check','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:09:27',NULL),(1261,-1000000001,'production','Order BA-064: Ready','Your order passed Quality Check and is ready for dispatch.','order-64',64,'normal','order',64,NULL,0,0,'2026-10-01 04:09:27',NULL),(1273,-1000000001,'production','Order BA-064: Out for Delivery','Your Brille Amour order is now out for delivery.','order-64',64,'normal','order',64,'delivery-out_for_delivery-64',0,0,'2026-10-01 04:10:13',NULL),(1281,-1000000001,'production','Order BA-064: Delivered','Your Brille Amour order has been delivered successfully.','order-64',64,'normal','order',64,'delivery-delivered-64',0,0,'2026-10-01 04:13:07',NULL),(1347,8,'order','New order BA-065','Brille amour - Customized Mugs x1','order-65',65,'normal','order',65,NULL,0,0,'2026-10-01 04:38:51',NULL),(1348,9,'order','New order BA-065','Brille amour - Customized Mugs x1','order-65',65,'normal','order',65,NULL,0,0,'2026-10-01 04:38:51',NULL),(1393,8,'order','New order BA-066','Brille amour - Customized Mugs x1','order-66',66,'normal','order',66,NULL,0,0,'2026-10-01 04:51:26',NULL),(1394,9,'order','New order BA-066','Brille amour - Customized Mugs x1','order-66',66,'normal','order',66,NULL,0,0,'2026-10-01 04:51:26',NULL),(1465,8,'order','New order BA-067','Mark Louie Salizon - Tarpaulin Printing x1','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:42:17',NULL),(1466,9,'order','New order BA-067','Mark Louie Salizon - Tarpaulin Printing x1','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:42:17',NULL),(1470,8,'order','New order BA-068','Mark Louie Salizon - Tarpaulin Printing x1','order-68',68,'normal','order',68,NULL,0,0,'2026-10-01 06:43:07',NULL),(1471,9,'order','New order BA-068','Mark Louie Salizon - Tarpaulin Printing x1','order-68',68,'normal','order',68,NULL,0,0,'2026-10-01 06:43:07',NULL),(1477,8,'production','BA-067 moved to In Production','Brille amour - from Pending','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:43:52',NULL),(1478,10,'production','Order awaiting acceptance: BA-067','A new In Production order is in your stage. Open the Production Board to accept or decline it.','order-67',67,'normal','order',67,'stage-invite-in_production-67-10',0,0,'2026-10-01 06:43:52',NULL),(1479,-1000000003,'production','Order BA-067: In Production','Your order has entered the Production stage and is awaiting staff acceptance before work begins.','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:43:52',NULL),(1489,-1000000003,'production','Order BA-067: Production started','A staff member has accepted your order and begun production.',NULL,67,'normal','order',67,'stage-accepted-in_production-67',0,0,'2026-10-01 06:44:46',NULL),(1490,8,'production','BA-067 moved to Quality Check','Chris Ian Sedon - from In Production','order-67',67,'warning','order',67,NULL,0,0,'2026-10-01 06:44:50',NULL),(1491,9,'production','BA-067 moved to Quality Check','Chris Ian Sedon - from In Production','order-67',67,'warning','order',67,NULL,0,0,'2026-10-01 06:44:50',NULL),(1492,7,'production','Order awaiting acceptance: BA-067','A new Quality Check order is in your stage. Open the Production Board to accept or decline it.','order-67',67,'normal','order',67,'stage-invite-quality_check-67-7',0,0,'2026-10-01 06:44:50',NULL),(1493,-1000000003,'production','Order BA-067: Quality Check','Your order has entered the Quality Check stage and is awaiting staff acceptance before inspection begins.','order-67',67,'warning','order',67,NULL,0,0,'2026-10-01 06:44:50',NULL),(1531,-1000000003,'production','Order BA-067: Quality check started','A staff member has accepted your order for quality inspection.',NULL,67,'normal','order',67,'stage-accepted-quality_check-67',0,0,'2026-10-01 06:46:52',NULL),(1533,8,'production','BA-067 moved to Ready','Chris Ian Sedon - from Quality Check','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:46:55',NULL),(1534,9,'production','BA-067 moved to Ready','Chris Ian Sedon - from Quality Check','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:46:55',NULL),(1535,-1000000003,'production','Order BA-067: Ready','Your order is ready for pickup.','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:46:55',NULL),(1545,8,'production','BA-067 moved to Completed','Brille amour - from Ready','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:47:23',NULL),(1546,-1000000003,'production','Order BA-067: Completed','Your order is completed. Your final bill is now available in My Orders.','order-67',67,'normal','order',67,NULL,0,0,'2026-10-01 06:47:23',NULL),(1569,8,'inventory','Low stock: Blank Acrylic Keychain','Blank Acrylic Keychain has only 10 pc remaining.',NULL,NULL,'warning','inventory',143,'low-stock-143-20261001',0,0,'2026-10-01 06:48:43',NULL),(1570,9,'inventory','Low stock: Blank Acrylic Keychain','Blank Acrylic Keychain has only 10 pc remaining.',NULL,NULL,'warning','inventory',143,'low-stock-143-20261001',0,0,'2026-10-01 06:48:43',NULL),(2035,9,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261005',0,0,'2026-10-05 15:40:31',NULL),(2036,9,'deadline','Order overdue: BA-066','This order is past its due date.','order-66',66,'urgent','order',66,'overdue-66-20261005',0,0,'2026-10-05 15:40:31',NULL),(2267,7,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261005',0,0,'2026-10-05 15:58:00',NULL),(2328,7,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261006',0,0,'2026-10-05 16:22:25',NULL),(2347,9,'deadline','Order overdue: BA-20260413-9373','This order is past its due date.','order-56',56,'urgent','order',56,'overdue-56-20261006',0,0,'2026-10-05 16:21:11',NULL),(2348,9,'deadline','Order overdue: BA-066','This order is past its due date.','order-66',66,'urgent','order',66,'overdue-66-20261006',0,0,'2026-10-05 16:21:11',NULL);
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `order_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `feedback` varchar(600) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_feedback_order` (`order_id`),
  KEY `idx_order_feedback_customer` (`customer_id`),
  KEY `idx_order_feedback_rating` (`rating`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `order_feedback` WRITE;
/*!40000 ALTER TABLE `order_feedback` DISABLE KEYS */;
INSERT INTO `order_feedback` VALUES (1,62,1,5,'','2026-09-29 14:29:01','2026-09-29 14:29:01'),(2,67,3,5,'','2026-10-01 06:47:41','2026-10-01 06:47:41');
/*!40000 ALTER TABLE `order_feedback` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity_used` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `inventory_id` (`inventory_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (28,56,81,'Business Cards',5.00,'piece','AUTO_PRODUCT_STOCK'),(29,57,125,'Sublimation Transfer Paper A4',1.00,'sheet','AUTO_MATERIAL_STOCK'),(30,57,130,'Sublimation Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(31,57,131,'Sublimation Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(32,57,132,'Sublimation Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(33,57,133,'Sublimation Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(34,57,142,'Blank Sublimation Mug',1.00,'pc','AUTO_MATERIAL_STOCK'),(35,58,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(36,58,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(37,58,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(38,58,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(39,58,134,'Printable Tarpaulin (1 sq m)',1.00,'sq m','AUTO_MATERIAL_STOCK'),(40,59,120,'Card Stock A4 (200 gsm)',1.00,'sheet','AUTO_MATERIAL_STOCK'),(41,59,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(42,59,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(43,59,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(44,59,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(45,60,125,'Sublimation Transfer Paper A4',2.00,'sheet','AUTO_MATERIAL_STOCK'),(46,60,130,'Sublimation Ink - Cyan',2.00,'ml','AUTO_MATERIAL_STOCK'),(47,60,131,'Sublimation Ink - Magenta',2.00,'ml','AUTO_MATERIAL_STOCK'),(48,60,132,'Sublimation Ink - Yellow',2.00,'ml','AUTO_MATERIAL_STOCK'),(49,60,133,'Sublimation Ink - Black',2.00,'ml','AUTO_MATERIAL_STOCK'),(50,60,142,'Blank Sublimation Mug',2.00,'pc','AUTO_MATERIAL_STOCK'),(51,61,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(52,61,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(53,61,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(54,61,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(55,61,134,'Printable Tarpaulin (1 sq m)',1.00,'sq m','AUTO_MATERIAL_STOCK'),(56,62,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(57,62,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(58,62,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(59,62,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(60,62,134,'Printable Tarpaulin (1 sq m)',1.00,'sq m','AUTO_MATERIAL_STOCK'),(61,63,120,'Card Stock A4 (200 gsm)',1.00,'sheet','AUTO_MATERIAL_STOCK'),(62,63,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(63,63,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(64,63,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(65,63,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(66,64,125,'Sublimation Transfer Paper A4',1.00,'sheet','AUTO_MATERIAL_STOCK'),(67,64,130,'Sublimation Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(68,64,131,'Sublimation Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(69,64,132,'Sublimation Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(70,64,133,'Sublimation Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(71,64,142,'Blank Sublimation Mug',1.00,'pc','AUTO_MATERIAL_STOCK'),(72,65,125,'Sublimation Transfer Paper A4',1.00,'sheet','AUTO_MATERIAL_STOCK'),(73,65,130,'Sublimation Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(74,65,131,'Sublimation Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(75,65,132,'Sublimation Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(76,65,133,'Sublimation Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(77,65,142,'Blank Sublimation Mug',1.00,'pc','AUTO_MATERIAL_STOCK'),(78,66,125,'Sublimation Transfer Paper A4',1.00,'sheet','AUTO_MATERIAL_STOCK'),(79,66,130,'Sublimation Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(80,66,131,'Sublimation Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(81,66,132,'Sublimation Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(82,66,133,'Sublimation Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(83,66,142,'Blank Sublimation Mug',1.00,'pc','AUTO_MATERIAL_STOCK'),(84,67,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(85,67,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(86,67,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(87,67,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(88,67,134,'Printable Tarpaulin (1 sq m)',1.00,'sq m','AUTO_MATERIAL_STOCK'),(89,68,126,'Printing Ink - Cyan',1.00,'ml','AUTO_MATERIAL_STOCK'),(90,68,127,'Printing Ink - Magenta',1.00,'ml','AUTO_MATERIAL_STOCK'),(91,68,128,'Printing Ink - Yellow',1.00,'ml','AUTO_MATERIAL_STOCK'),(92,68,129,'Printing Ink - Black',1.00,'ml','AUTO_MATERIAL_STOCK'),(93,68,134,'Printable Tarpaulin (1 sq m)',1.00,'sq m','AUTO_MATERIAL_STOCK');
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(30) NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_contact` varchar(100) DEFAULT NULL,
  `source` enum('Facebook','Instagram','Walk-in','Referral','Other') DEFAULT 'Other',
  `order_type` varchar(100) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deposit_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `pickup_time` time DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `status` enum('pending','in_production','quality_check','ready','completed','cancelled','archived') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `task_title` varchar(160) DEFAULT NULL,
  `task_instructions` text DEFAULT NULL,
  `task_deadline` datetime DEFAULT NULL,
  `task_status` enum('not_started','in_progress','done','on_hold') NOT NULL DEFAULT 'not_started',
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `customer_id` int(11) DEFAULT NULL,
  `fulfillment_method` varchar(20) NOT NULL DEFAULT 'pickup',
  `delivery_address` text DEFAULT NULL,
  `production_hold_reason` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`),
  KEY `idx_orders_customer_id` (`customer_id`),
  KEY `idx_orders_task_status` (`task_status`),
  KEY `idx_orders_task_deadline` (`task_deadline`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (56,'BA-20260413-9373','Chris Ian Sedon','09463071088','Facebook','Business Cards',2,'',5,123.00,615.00,0.00,0.00,615.00,'2026-04-23',NULL,'','','',NULL,NULL,7,NULL,NULL,NULL,'not_started',NULL,NULL,7,'2026-04-13 05:30:15','2026-04-13 05:30:15',NULL,'pickup',NULL,''),(57,'BA-057','Brille amour','+639936084029','Other','Customized Mugs',3,'',1,70.00,150.00,80.00,37.50,112.50,'2026-09-30','17:30:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-27 04:30:54','2026-09-27 04:37:11',1,'delivery','medija village,sitio bula',''),(58,'BA-058','Brille amour','+639936084029','Other','Tarpaulin Printing',11,'Selected size: 2 × 3 ft',1,120.00,120.00,0.00,0.00,120.00,'2026-10-10','17:45:00','normal','pending','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-27 04:41:48','2026-09-27 04:41:48',1,'pickup',NULL,''),(59,'BA-059','Brille amour','+639936084029','Other','Business Cards',2,'Selected size: 100 pcs · Standard (3.5 × 2 in)',1,290.00,370.00,80.00,370.00,0.00,'2026-09-29','22:25:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-29 13:24:42','2026-09-29 13:27:35',1,'delivery','medija village,sitio bula',''),(60,'BA-060','Brille amour','+639936084029','Other','Customized Mugs',3,'',2,70.00,180.00,40.00,180.00,0.00,'2026-09-30','15:40:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-29 13:37:05','2026-09-29 13:39:54',1,'delivery','medija village,sitio bula',''),(61,'BA-061','Brille amour','+639936084029','Other','Tarpaulin Printing',11,'Selected size: 2 × 3 ft',1,120.00,160.00,40.00,160.00,0.00,'2026-09-30','15:40:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-29 13:37:05','2026-09-29 13:48:12',1,'delivery','medija village,sitio bula',''),(62,'BA-062','Brille amour','+6399360840292','Other','Tarpaulin Printing',11,'Selected size: 2 × 3 ft',1,120.00,200.00,80.00,200.00,0.00,'2026-10-10','15:05:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-29 14:00:27','2026-09-29 14:02:19',1,'delivery','medija village,sitio bula',''),(63,'BA-063','Brille amour','+6399360840292','Other','Business Cards',2,'Selected size: 100 pcs · Standard (3.5 × 2 in)',1,290.00,370.00,80.00,0.00,370.00,'2026-10-08','14:15:00','normal','pending','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-09-29 14:14:13','2026-09-29 14:14:13',1,'delivery','medija village,sitio bula',''),(64,'BA-064','Brille amour','+6399360840292','Other','Customized Mugs',3,'',1,70.00,70.00,0.00,70.00,0.00,'2026-10-10','12:10:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-10-01 04:05:40','2026-10-01 04:13:07',1,'delivery','medija village,sitio bula',''),(65,'BA-065','Brille amour','+6399360840292','Other','Customized Mugs',3,'',1,70.00,70.00,0.00,0.00,70.00,'2026-10-27','18:40:00','normal','pending','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-10-01 04:38:51','2026-10-01 04:38:51',1,'pickup',NULL,''),(66,'BA-066','Brille amour','+6399360840292','Other','Customized Mugs',3,'',1,70.00,70.00,0.00,0.00,70.00,'2026-10-03','12:55:00','normal','pending','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-10-01 04:51:25','2026-10-01 04:51:25',1,'pickup',NULL,''),(67,'BA-067','Mark Louie Salizon','+639936084029','Other','Tarpaulin Printing',11,'Selected size: 2 × 3 ft',1,120.00,120.00,0.00,30.00,90.00,'2026-10-09','19:05:00','normal','completed','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-10-01 06:42:17','2026-10-01 06:47:22',3,'pickup',NULL,''),(68,'BA-068','Mark Louie Salizon','+639936084029','Other','Tarpaulin Printing',11,'Selected size: 2 × 3 ft',1,120.00,120.00,0.00,0.00,120.00,'2026-10-16','14:45:00','normal','pending','',NULL,NULL,NULL,NULL,NULL,NULL,'not_started',NULL,NULL,8,'2026-10-01 06:43:07','2026-10-01 06:43:07',3,'pickup',NULL,'');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payment_receipt_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_receipt_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `actor_type` varchar(20) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action_key` varchar(80) NOT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_receipt_audit_payment` (`payment_id`,`created_at`),
  KEY `idx_receipt_audit_order` (`order_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payment_receipt_audit` WRITE;
/*!40000 ALTER TABLE `payment_receipt_audit` DISABLE KEYS */;
INSERT INTO `payment_receipt_audit` VALUES (1,1,57,'admin',9,'payment_verified','','2026-09-27 12:35:43'),(2,3,59,'admin',9,'payment_verified','','2026-09-29 21:26:24'),(3,4,60,'admin',9,'payment_verified','','2026-09-29 21:37:39'),(4,4,61,'admin',9,'payment_verified','','2026-09-29 21:37:39'),(5,5,62,'admin',9,'payment_verified','','2026-09-29 22:01:06'),(6,7,64,'admin',9,'payment_verified','','2026-10-01 12:07:32'),(7,10,67,'admin',9,'payment_verified','','2026-10-01 14:43:45');
/*!40000 ALTER TABLE `payment_receipt_audit` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payment_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_receipts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `receipt_number` varchar(40) NOT NULL,
  `barcode_value` varchar(80) NOT NULL,
  `receipt_status` enum('paid','partial','refunded','reversed') NOT NULL DEFAULT 'paid',
  `status_note` varchar(500) NOT NULL DEFAULT '',
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_id` (`payment_id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  UNIQUE KEY `barcode_value` (`barcode_value`),
  KEY `idx_payment_receipts_status` (`receipt_status`),
  KEY `idx_payment_receipts_issued` (`issued_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payment_receipts` WRITE;
/*!40000 ALTER TABLE `payment_receipts` DISABLE KEYS */;
INSERT INTO `payment_receipts` VALUES (1,1,'BA-R-20260927-000001','BA-R-20260927-000001','partial','','2026-09-27 12:31:06','2026-09-27 12:31:06'),(4,2,'BA-R-20260927-000002','BA-R-20260927-000002','partial','','2026-09-27 12:42:00','2026-09-27 12:42:00'),(6,3,'BA-R-20260929-000003','BA-R-20260929-000003','paid','','2026-09-29 21:25:22','2026-09-29 21:25:22'),(9,4,'BA-R-20260929-000004','BA-R-20260929-000004','paid','','2026-09-29 21:37:21','2026-09-29 21:37:21'),(11,5,'BA-R-20260929-000005','BA-R-20260929-000005','paid','','2026-09-29 22:00:43','2026-09-29 22:00:43'),(13,6,'BA-R-20260929-000006','BA-R-20260929-000006','paid','','2026-09-29 22:14:31','2026-09-29 22:14:31'),(15,7,'BA-R-20261001-000007','BA-R-20261001-000007','paid','','2026-10-01 12:05:58','2026-10-01 12:05:58'),(18,9,'BA-R-20261001-000009','BA-R-20261001-000009','partial','','2026-10-01 12:51:48','2026-10-01 12:51:48'),(20,10,'BA-R-20261001-000010','BA-R-20261001-000010','partial','','2026-10-01 14:42:32','2026-10-01 14:42:32'),(22,11,'BA-R-20261001-000011','BA-R-20261001-000011','partial','','2026-10-01 14:43:22','2026-10-01 14:43:22');
/*!40000 ALTER TABLE `payment_receipts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `paymongo_checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paymongo_checkouts` (
  `payment_id` int(11) NOT NULL,
  `return_token` char(64) NOT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `checkout_url` text DEFAULT NULL,
  `flow_type` varchar(20) NOT NULL DEFAULT 'hosted',
  `payment_intent_id` varchar(100) DEFAULT NULL,
  `client_key` varchar(255) DEFAULT NULL,
  `payment_method_id` varchar(100) DEFAULT NULL,
  `redirect_url` text DEFAULT NULL,
  `state` varchar(30) NOT NULL DEFAULT 'new',
  `livemode` tinyint(1) NOT NULL,
  `paid_payment_id` varchar(100) DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `return_token` (`return_token`),
  UNIQUE KEY `session_id` (`session_id`),
  UNIQUE KEY `paid_payment_id` (`paid_payment_id`),
  KEY `idx_paymongo_payment_intent` (`payment_intent_id`),
  CONSTRAINT `paymongo_checkouts_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `checkout_payments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `paymongo_checkouts` WRITE;
/*!40000 ALTER TABLE `paymongo_checkouts` DISABLE KEYS */;
INSERT INTO `paymongo_checkouts` VALUES (1,'00cdc2f649bb29027c5668be166373c48e657ff6533524fca7f47c952527e6ea',NULL,NULL,'wallet','pi_68Sg9RwcGR6VY5mq8G9sSBC7','pi_68Sg9RwcGR6VY5mq8G9sSBC7_client_g12q2o8bQ2K7Pi9moEG6q9sn','pm_Sy8fvrvd4TbQ3FA3nLfGNBwA',NULL,'test_paid',0,'pay_BzH4KAdtoHeXPEZzHbHSSCHQ','2026-09-27 12:31:06','2026-09-27 04:30:54'),(2,'14119e07e9ea65d10d136477ccb3c9db5500f239caa44cdb031ba4aed3311346',NULL,NULL,'wallet','pi_d7J7QWi8UvNADWFyeuaBF6yn','pi_d7J7QWi8UvNADWFyeuaBF6yn_client_NW4yGixGX1p4M7GCqH6YQ1m4','pm_FF742tZq9DtXmVJrmsy5jEp2',NULL,'test_paid',0,'pay_Gh6VGWQ3b2MpM7cG7HvLtZnH','2026-09-27 12:41:59','2026-09-27 04:41:48'),(3,'dba4ca232342c44136b3cb2699155f2bc06e623495485544515897c8e9a1ae9a',NULL,NULL,'wallet','pi_iMusHZhAA4WemWAKH8VKcF3s','pi_iMusHZhAA4WemWAKH8VKcF3s_client_LBUx5raNg4qefEjZXUH6EsSK','pm_n6ArKh6qANCsBSMkkjy9YhpS',NULL,'test_paid',0,'pay_vycyk8DigmNSn8coNkGYwQrx','2026-09-29 21:25:20','2026-09-29 13:24:42'),(4,'1665df52b0d04e3f6006c651cfbcf80ff077006b035e74dae742c0e55260b19e',NULL,NULL,'wallet','pi_MiXVXvV5Lxa1LkJHx4qREuTZ','pi_MiXVXvV5Lxa1LkJHx4qREuTZ_client_gAzNXWFhzj3AtjZpwbFNhanD','pm_TUkSaMBMvRfsdXuokwd7BJgg',NULL,'test_paid',0,'pay_YMw1wo95wycQANsR8UAhbrnU','2026-09-29 21:37:21','2026-09-29 13:37:05'),(5,'2c998fb2051468c01d72143c51bea56f5682cbfb968ba48cef7398cb521c1f30',NULL,NULL,'wallet','pi_zPgf6nVH2HCCEeCCyfr9Kp7p','pi_zPgf6nVH2HCCEeCCyfr9Kp7p_client_oXi6DjaH6Hkh3dbUbRYSpfFA','pm_cLaQ6EU9zwAfhX5Eqz8giNUF',NULL,'test_paid',0,'pay_NrgFKQniKpn1PUsF4no1FCXB','2026-09-29 22:00:42','2026-09-29 14:00:27'),(6,'3f3edf9d33044860a7da0aa824e7cc09e32be553a91954aeaf60810843a74a8b',NULL,NULL,'wallet','pi_hR4hkBvBvwRu3yUT7vwW9QjW','pi_hR4hkBvBvwRu3yUT7vwW9QjW_client_9FmGYn1EuA5HyRbe7bwiJfQt','pm_kABQcTPEbqy33fkVF27ZqLPM',NULL,'test_paid',0,'pay_99gcLSBZXkfaS6CEwH4mbwTv','2026-09-29 22:14:31','2026-09-29 14:14:13'),(7,'150dfefa69590c210c0ae4884dcd9fa1c080ea73769c782b9817497fd441c87d',NULL,NULL,'wallet','pi_dm78XQKKoAxaWVNdTNoaHejy','pi_dm78XQKKoAxaWVNdTNoaHejy_client_avdf75qnCAgj68TupMUkzaLo','pm_bnzXeUZVRrDGLdYcr4eBoZVZ',NULL,'test_paid',0,'pay_AL5QgCcxixhiU56YB1VgEUo6','2026-10-01 12:05:57','2026-10-01 04:05:40'),(8,'e8776e7c6ed9f5a2ae931155b2758ababb262099ed7bae94e0ed294e435b1fd2',NULL,NULL,'hosted',NULL,NULL,NULL,NULL,'new',0,NULL,NULL,'2026-10-01 04:38:51'),(9,'63d8f5fcb9f0dee178739603698fe19237be8ec74259012833c8b600b78d8a53',NULL,NULL,'wallet','pi_1XJ9uRHEuQWycqePNgwWgasT','pi_1XJ9uRHEuQWycqePNgwWgasT_client_XtFoH7oPsi7aqCxS1Fattw4H','pm_ryNTNixkybBvmDKRz8zHZ4h2',NULL,'test_paid',0,'pay_FhyUaQQSLP6ynwjANwWhX7PM','2026-10-01 12:51:46','2026-10-01 04:51:26'),(10,'be7c143f5cee63704f50dcde8262f05aec344967dcb4e66a000d02e5842fdbd3',NULL,NULL,'wallet','pi_RcgSP8YWEibXZS2ZdxZBvyHV','pi_RcgSP8YWEibXZS2ZdxZBvyHV_client_jHbVhQCjKYxZ8gi8fuyKzuRK','pm_6yKWMxP1xzXJF7R9Xo5AGiYR',NULL,'test_paid',0,'pay_3sbLyr5fomtuxTcyZyGK2gCB','2026-10-01 14:42:31','2026-10-01 06:42:17'),(11,'8a40205ca747805bd92022077d0f8af5067807977b5b5dadbd6cb63cd7a09a9a',NULL,NULL,'wallet','pi_kMVNR843FbKjZ8dHc1Qpdmxf','pi_kMVNR843FbKjZ8dHc1Qpdmxf_client_8MdHdf4s5dxGUMYGwE3m9nKf','pm_Fdm74mphi9661ypEHwx2q9aF',NULL,'test_paid',0,'pay_LtfCDZx5GqAzuvnmVgJqaDu9','2026-10-01 14:43:21','2026-10-01 06:43:07');
/*!40000 ALTER TABLE `paymongo_checkouts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `pickup_capacity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pickup_capacity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pickup_date` date NOT NULL,
  `max_orders` int(11) NOT NULL DEFAULT 20,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `pickup_date` (`pickup_date`),
  KEY `idx_pickup_capacity_date` (`pickup_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `pickup_capacity` WRITE;
/*!40000 ALTER TABLE `pickup_capacity` DISABLE KEYS */;
/*!40000 ALTER TABLE `pickup_capacity` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `preorder_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preorder_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sender_role` enum('customer','admin') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `preorder_messages` WRITE;
/*!40000 ALTER TABLE `preorder_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `preorder_messages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `preorder_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preorder_migrations` (
  `version` varchar(64) NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `preorder_migrations` WRITE;
/*!40000 ALTER TABLE `preorder_migrations` DISABLE KEYS */;
INSERT INTO `preorder_migrations` VALUES ('initial_preorder_modes_20260925');
/*!40000 ALTER TABLE `preorder_migrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `preorder_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preorder_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `quantity` int(11) NOT NULL,
  `requested_size` varchar(120) NOT NULL DEFAULT '',
  `details` text NOT NULL,
  `reference_image` varchar(255) DEFAULT NULL,
  `status` enum('requested','discussing','quoted','accepted','confirmed','payment_started','cancelled') NOT NULL DEFAULT 'requested',
  `quoted_total` decimal(10,2) DEFAULT NULL,
  `quoted_details` text DEFAULT NULL,
  `estimated_date` date DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `status` (`status`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `preorder_requests` WRITE;
/*!40000 ALTER TABLE `preorder_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `preorder_requests` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_materials` (
  `product_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `qty_per_unit` decimal(10,2) NOT NULL,
  PRIMARY KEY (`product_id`,`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_materials` WRITE;
/*!40000 ALTER TABLE `product_materials` DISABLE KEYS */;
INSERT INTO `product_materials` VALUES (2,120,1.00),(2,126,1.00),(2,127,1.00),(2,128,1.00),(2,129,1.00),(3,125,1.00),(3,130,1.00),(3,131,1.00),(3,132,1.00),(3,133,1.00),(3,142,1.00),(6,121,1.00),(6,126,1.00),(6,127,1.00),(6,128,1.00),(6,129,1.00),(9,120,1.00),(9,126,1.00),(9,127,1.00),(9,128,1.00),(9,129,1.00),(11,126,1.00),(11,127,1.00),(11,128,1.00),(11,129,1.00),(11,134,1.00),(12,126,1.00),(12,127,1.00),(12,128,1.00),(12,129,1.00),(12,135,1.00),(12,136,1.00),(13,125,1.00),(13,130,1.00),(13,131,1.00),(13,132,1.00),(13,133,1.00),(13,143,100.00),(14,120,1.00),(14,126,1.00),(14,127,1.00),(14,128,1.00),(14,129,1.00),(14,145,1.00),(15,120,1.00),(15,126,1.00),(15,127,1.00),(15,128,1.00),(15,129,1.00),(15,144,1.00),(16,126,1.00),(16,127,1.00),(16,128,1.00),(16,129,1.00),(16,146,1.00),(17,116,1.00),(17,126,1.00),(17,127,1.00),(17,128,1.00),(17,129,1.00),(18,124,1.00),(18,126,1.00),(18,127,1.00),(18,128,1.00),(18,129,1.00),(19,122,1.00),(19,126,1.00),(19,127,1.00),(19,128,1.00),(19,129,1.00),(20,122,1.00),(20,126,1.00),(20,127,1.00),(20,128,1.00),(20,129,1.00);
/*!40000 ALTER TABLE `product_materials` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_size_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_size_prices` (
  `product_id` int(11) NOT NULL,
  `size_label` varchar(190) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`,`size_label`),
  KEY `idx_product_size_prices_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_size_prices` WRITE;
/*!40000 ALTER TABLE `product_size_prices` DISABLE KEYS */;
INSERT INTO `product_size_prices` VALUES (13,'1.5 in',65.00,'2026-09-27 03:44:17'),(13,'2 in',81.25,'2026-09-27 03:44:17'),(13,'2.5 in',104.00,'2026-09-27 03:44:17');
/*!40000 ALTER TABLE `product_size_prices` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_stock_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_stock_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `movement` enum('reserve','restore','adjust') NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_stock_product` (`product_id`),
  KEY `idx_product_stock_order` (`order_id`),
  KEY `idx_product_stock_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_stock_transactions` WRITE;
/*!40000 ALTER TABLE `product_stock_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_stock_transactions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `production_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `production_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `production_logs_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_logs_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `production_logs` WRITE;
/*!40000 ALTER TABLE `production_logs` DISABLE KEYS */;
INSERT INTO `production_logs` VALUES (114,56,'','pending','Order created',7,'2026-04-13 05:30:15'),(115,57,'','pending','Customer order submitted',8,'2026-09-27 04:30:54'),(116,57,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-27 04:31:06'),(117,57,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated downpayment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-09-27 04:35:43'),(118,57,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-09-27 04:35:43'),(119,57,'in_production','in_production','Accepted in production assignment',7,'2026-09-27 04:36:30'),(120,57,'in_production','quality_check','Status updated from production board',7,'2026-09-27 04:36:33'),(121,57,'quality_check','quality_check','Accepted quality check assignment',7,'2026-09-27 04:36:55'),(122,57,'quality_check','ready','Status updated from production board',7,'2026-09-27 04:36:59'),(123,57,'ready','completed','Status updated from production board',9,'2026-09-27 04:37:11'),(124,58,'','pending','Customer order submitted',8,'2026-09-27 04:41:48'),(125,58,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-27 04:42:00'),(126,59,'','pending','Customer order submitted',8,'2026-09-29 13:24:42'),(127,59,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-29 13:25:22'),(128,59,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-09-29 13:26:24'),(129,59,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-09-29 13:26:24'),(130,59,'in_production','in_production','Accepted in production assignment',10,'2026-09-29 13:26:54'),(131,59,'in_production','quality_check','Status updated from production board',10,'2026-09-29 13:26:58'),(132,59,'quality_check','quality_check','Accepted quality check assignment',7,'2026-09-29 13:27:11'),(133,59,'quality_check','ready','Status updated from production board',7,'2026-09-29 13:27:14'),(134,59,'ready','completed','Status updated from production board',9,'2026-09-29 13:27:35'),(135,60,'','pending','Customer order submitted',8,'2026-09-29 13:37:05'),(136,61,'','pending','Customer order submitted',8,'2026-09-29 13:37:05'),(137,60,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-29 13:37:21'),(138,61,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-29 13:37:21'),(139,60,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-09-29 13:37:39'),(140,61,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-09-29 13:37:39'),(141,60,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-09-29 13:37:39'),(142,61,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-09-29 13:37:39'),(143,60,'in_production','in_production','Accepted in production assignment',10,'2026-09-29 13:38:24'),(144,60,'in_production','quality_check','Status updated from production board',10,'2026-09-29 13:38:29'),(145,61,'in_production','in_production','Accepted in production assignment',10,'2026-09-29 13:38:44'),(146,61,'in_production','quality_check','Status updated from production board',10,'2026-09-29 13:38:46'),(147,60,'quality_check','quality_check','Accepted quality check assignment',7,'2026-09-29 13:39:12'),(148,60,'quality_check','ready','Status updated from production board',7,'2026-09-29 13:39:15'),(149,60,'ready','completed','Delivery confirmed',9,'2026-09-29 13:39:54'),(150,61,'quality_check','quality_check','Accepted quality check assignment',7,'2026-09-29 13:47:38'),(151,61,'quality_check','ready','Status updated from production board',7,'2026-09-29 13:47:44'),(152,61,'ready','completed','Delivery confirmed',9,'2026-09-29 13:48:12'),(153,62,'','pending','Customer order submitted',8,'2026-09-29 14:00:27'),(154,62,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-29 14:00:43'),(155,62,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-09-29 14:01:06'),(156,62,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-09-29 14:01:06'),(157,62,'in_production','in_production','Accepted in production assignment',10,'2026-09-29 14:01:24'),(158,62,'in_production','quality_check','Status updated from production board',10,'2026-09-29 14:01:26'),(159,62,'quality_check','quality_check','Accepted quality check assignment',7,'2026-09-29 14:01:39'),(160,62,'quality_check','ready','Status updated from production board',7,'2026-09-29 14:01:42'),(161,62,'ready','completed','Delivery confirmed',9,'2026-09-29 14:02:19'),(162,63,'','pending','Customer order submitted',8,'2026-09-29 14:14:13'),(163,63,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-09-29 14:14:31'),(164,64,'','pending','Customer order submitted',8,'2026-10-01 04:05:40'),(165,64,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-10-01 04:05:58'),(166,64,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-10-01 04:07:32'),(167,64,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-10-01 04:07:32'),(168,64,'in_production','in_production','Accepted in production assignment',10,'2026-10-01 04:08:43'),(169,64,'in_production','quality_check','Status updated from production board',10,'2026-10-01 04:09:11'),(170,64,'quality_check','quality_check','Accepted quality check assignment',7,'2026-10-01 04:09:20'),(171,64,'quality_check','ready','Status updated from production board',7,'2026-10-01 04:09:26'),(172,64,'ready','completed','Delivery confirmed',9,'2026-10-01 04:13:07'),(173,65,'','pending','Customer order submitted',8,'2026-10-01 04:38:51'),(174,66,'','pending','Customer order submitted',8,'2026-10-01 04:51:26'),(175,66,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-10-01 04:51:48'),(176,67,'','pending','Customer order submitted',8,'2026-10-01 06:42:17'),(177,67,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-10-01 06:42:32'),(178,68,'','pending','Customer order submitted',8,'2026-10-01 06:43:07'),(179,68,'pending','pending','PayMongo test payment confirmed. Waiting for admin verification before production starts.',NULL,'2026-10-01 06:43:22'),(180,67,'pending','pending','Admin verified PayMongo TEST payment and credited the simulated payment. Order entered the FIFO Production Queue and will be released automatically when capacity is available',9,'2026-10-01 06:43:45'),(181,67,'pending','in_production','Automatically released from Production Queue (FIFO) when a production slot became available.',9,'2026-10-01 06:43:45'),(182,67,'in_production','in_production','Accepted in production assignment',10,'2026-10-01 06:44:46'),(183,67,'in_production','quality_check','Status updated from production board',10,'2026-10-01 06:44:50'),(184,67,'quality_check','quality_check','Accepted quality check assignment',7,'2026-10-01 06:46:52'),(185,67,'quality_check','ready','Status updated from production board',7,'2026-10-01 06:46:55'),(186,67,'ready','completed','Status updated from production board',9,'2026-10-01 06:47:22');
/*!40000 ALTER TABLE `production_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `production_stage_acceptance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `production_stage_acceptance` (
  `order_id` int(11) NOT NULL,
  `stage` varchar(32) NOT NULL,
  `decision` enum('accepted','declined') NOT NULL,
  `staff_id` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `decided_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`order_id`,`stage`),
  KEY `idx_stage_decision` (`stage`,`decision`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `production_stage_acceptance` WRITE;
/*!40000 ALTER TABLE `production_stage_acceptance` DISABLE KEYS */;
INSERT INTO `production_stage_acceptance` VALUES (57,'in_production','accepted',7,'','2026-09-27 04:36:30'),(57,'quality_check','accepted',7,'','2026-09-27 04:36:55'),(59,'in_production','accepted',10,'','2026-09-29 13:26:54'),(59,'quality_check','accepted',7,'','2026-09-29 13:27:11'),(60,'in_production','accepted',10,'','2026-09-29 13:38:24'),(60,'quality_check','accepted',7,'','2026-09-29 13:39:12'),(61,'in_production','accepted',10,'','2026-09-29 13:38:44'),(61,'quality_check','accepted',7,'','2026-09-29 13:47:38'),(62,'in_production','accepted',10,'','2026-09-29 14:01:24'),(62,'quality_check','accepted',7,'','2026-09-29 14:01:39'),(64,'in_production','accepted',10,'','2026-10-01 04:08:43'),(64,'quality_check','accepted',7,'','2026-10-01 04:09:20'),(67,'in_production','accepted',10,'','2026-10-01 06:44:46'),(67,'quality_check','accepted',7,'','2026-10-01 06:46:52');
/*!40000 ALTER TABLE `production_stage_acceptance` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `production_stage_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `production_stage_queue` (
  `order_id` int(11) NOT NULL,
  `source_stage` varchar(40) NOT NULL,
  `target_stage` varchar(40) NOT NULL,
  `queued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `queued_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`order_id`),
  KEY `idx_target_fifo` (`target_stage`,`queued_at`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `production_stage_queue` WRITE;
/*!40000 ALTER TABLE `production_stage_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `production_stage_queue` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock_qty` int(10) unsigned NOT NULL DEFAULT 25,
  `low_stock_threshold` int(10) unsigned NOT NULL DEFAULT 5,
  `unit` varchar(30) NOT NULL DEFAULT 'piece',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `sort_order` int(11) NOT NULL DEFAULT 999,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `order_mode` enum('regular','preorder') NOT NULL DEFAULT 'regular',
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_inventory` (`inventory_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,8,104,'Birthday Package','Imported from existing order history for Birthday Package',NULL,999.00,25,5,'piece','normal',100,'archived','2026-04-12 14:47:35','2026-09-27 03:22:39','regular'),(2,10,NULL,'Business Cards','Business card printing: 100 copies per set.',NULL,290.00,25,5,'set','normal',100,'active','2026-04-12 14:47:35','2026-09-27 03:22:40','regular'),(3,11,NULL,'Customized Mugs','Photo and name printing on personalized mugs. Send your design, text and quantity.',NULL,70.00,25,5,'piece','normal',50,'active','2026-04-12 14:47:35','2026-09-27 03:22:40','regular'),(4,5,78,'Flyers','',NULL,180.00,25,5,'piece','normal',20,'archived','2026-04-12 15:34:35','2026-09-27 03:22:39','regular'),(5,5,79,'Brochures','',NULL,350.00,25,5,'piece','normal',30,'archived','2026-04-12 15:34:35','2026-09-27 03:22:39','regular'),(6,9,NULL,'Posters','Full-color promotional posters for events, shops and announcements. Specify dimensions, material and your print-ready artwork.',NULL,120.00,25,5,'piece','normal',30,'archived','2026-04-12 15:34:35','2026-09-27 03:22:40','regular'),(7,5,84,'Stickers','',NULL,85.00,25,5,'piece','normal',50,'archived','2026-04-12 15:34:35','2026-09-27 03:22:39','regular'),(8,5,85,'Labels','',NULL,95.00,25,5,'piece','normal',60,'archived','2026-04-12 15:34:35','2026-09-27 03:22:39','regular'),(9,10,NULL,'Invitations','Printed invitations for birthdays, weddings and celebrations. Provide event details, size and paper preference.',NULL,60.00,25,5,'piece','normal',150,'active','2026-04-12 15:34:35','2026-09-27 03:22:40','preorder'),(10,6,115,'Customized Shirts','',NULL,450.00,25,5,'piece','normal',80,'archived','2026-04-12 15:34:35','2026-09-27 03:22:39','regular'),(11,9,NULL,'Tarpaulin Printing','Custom tarpaulin banners for birthdays, business promos and events. Provide your width, height, artwork and eyelet requirements.',NULL,120.00,25,5,'piece','normal',10,'active','2026-09-27 03:22:39','2026-09-27 03:22:39','regular'),(12,9,NULL,'Sintra Board','Printed rigid PVC Sintra signboard for menus, business signs and displays. Specify your dimensions, thickness and print design.',NULL,250.00,25,5,'piece','normal',20,'active','2026-09-27 03:22:39','2026-09-27 03:22:40','preorder'),(13,11,NULL,'Acrylic Keychains','Custom printed acrylic keychains with your photo, logo or name. Select shape, dimensions and quantity.','',65.00,25,5,'piece','normal',60,'active','2026-09-27 03:22:39','2026-09-27 03:44:17','preorder'),(14,11,NULL,'Button Pins','Printed button pins for schools, events and branded giveaways. Specify pin size, artwork and quantity.',NULL,35.00,25,5,'piece','normal',70,'active','2026-09-27 03:22:39','2026-09-27 03:22:40','preorder'),(15,11,NULL,'Ref Magnets','Personalized refrigerator magnets with photos, logos and custom text. Specify size and quantity.',NULL,55.00,25,5,'piece','normal',80,'active','2026-09-27 03:22:39','2026-09-27 03:22:40','preorder'),(16,11,NULL,'Bookmarks','Full-color custom printed bookmarks with your preferred layout and finish. Specify dimensions and quantity.',NULL,25.00,25,5,'piece','normal',90,'active','2026-09-27 03:22:39','2026-09-27 03:22:40','preorder'),(17,10,NULL,'Flyers/Brochure','Choose flyer or brochure size and print bundle.',NULL,175.00,25,5,'piece','normal',110,'active','2026-09-27 03:22:39','2026-09-27 03:22:39','regular'),(18,10,NULL,'Stickers/Labels','Choose a sticker/label bundle or a single piece.',NULL,107.00,25,5,'piece','normal',130,'active','2026-09-27 03:22:39','2026-09-27 03:22:39','regular'),(19,12,NULL,'Photo Printing','Print your photos in your chosen photo paper size and finish. Upload the original high-resolution files.',NULL,15.00,25,5,'piece','normal',160,'active','2026-09-27 03:22:39','2026-09-27 03:22:39','regular'),(20,12,NULL,'ID Picture Printing','One ID photo package: four 2×2, eight 1×1 and two passport prints.',NULL,45.00,25,5,'set','normal',170,'active','2026-09-27 03:22:39','2026-09-27 03:22:39','regular');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `staff_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `staff_activity_log_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `staff_activity_log` WRITE;
/*!40000 ALTER TABLE `staff_activity_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_activity_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `staff_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` datetime NOT NULL,
  `time_out` datetime DEFAULT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'manual',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_attendance_day` (`user_id`,`attendance_date`),
  KEY `idx_staff_attendance_date` (`attendance_date`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `staff_attendance` WRITE;
/*!40000 ALTER TABLE `staff_attendance` DISABLE KEYS */;
INSERT INTO `staff_attendance` VALUES (1,7,'2026-09-27','2026-09-27 12:05:23',NULL,'login',NULL,'2026-09-27 04:05:23','2026-09-27 04:05:23'),(2,7,'2026-09-29','2026-09-29 21:08:34',NULL,'login',NULL,'2026-09-29 13:08:34','2026-09-29 13:08:34'),(3,10,'2026-09-29','2026-09-29 21:10:47',NULL,'login',NULL,'2026-09-29 13:10:47','2026-09-29 13:10:47'),(4,7,'2026-10-01','2026-10-01 12:02:24',NULL,'login',NULL,'2026-10-01 04:02:24','2026-10-01 04:02:24'),(5,10,'2026-10-01','2026-10-01 12:03:27',NULL,'login',NULL,'2026-10-01 04:03:27','2026-10-01 04:03:27'),(6,7,'2026-10-05','2026-10-05 23:41:00',NULL,'login',NULL,'2026-10-05 15:41:00','2026-10-05 15:41:00');
/*!40000 ALTER TABLE `staff_attendance` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `employee_id` varchar(32) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `staff_role` enum('production','quality_check') DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `idx_users_employee_id` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (7,'ian123','BA-STF-00007','$2y$10$bt2dAJRyq6Lu5Jv2I2BlvOeXD4/QqU1A6OpuchflMhKKpTQ2GE9Ma','Chris Ian Sedon','chrisian_sedon@sjp2cd.edu.ph','staff','quality_check','active','2026-03-25 14:19:48','2026-09-27 04:36:49'),(8,'boss',NULL,'$2y$10$Dl30IvgDZXgJP402bmG1ZunbhYwOqAEBbR.gKhG.aE9ldS3026QOa','boss1','boss@gmail.com','admin',NULL,'active','2026-03-25 14:21:01','2026-03-25 14:21:01'),(9,'brilleamour2@gmail.com',NULL,'$2y$10$/XE4QAaWGmhgjJfpxb2eX.HnE0Ip6vp/YwDXLR8yCRboGBGL5QHKy','Brille amour','brilleamour2@gmail.com','admin',NULL,'active','2026-09-27 03:26:59','2026-09-27 03:26:59'),(10,'chrisiansedon6@gmail.com','BA-STF-00010','$2y$10$2evONWrXXRw6tzmfc9MD1uUwdIIhU65XIbRk0f7bW4vDMjOaHyfo2','Chris Ian Sedon','chrisiansedon6@gmail.com','staff','production','active','2026-09-29 13:10:13','2026-09-29 13:10:28');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

