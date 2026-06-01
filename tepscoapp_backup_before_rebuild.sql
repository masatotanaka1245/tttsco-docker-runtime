-- MySQL dump 10.13  Distrib 8.0.46, for Linux (aarch64)
--
-- Host: localhost    Database: tepscoapp
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `tepscoapp`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `tepscoapp` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `tepscoapp`;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL COMMENT 'æ‰€å±žãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆID',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'è³‡æ–™ã‚¿ã‚¤ãƒˆãƒ«',
  `file_path` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ã‚µãƒ¼ãƒãƒ¼ä¸Šã®çµ¶å¯¾ãƒ‘ã‚¹',
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'pdf' COMMENT 'æ‹¡å¼µå­',
  `file_size` int unsigned DEFAULT NULL COMMENT 'ãƒã‚¤ãƒˆæ•°',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project_docs` (`project_id`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆé–¢é€£è³‡æ–™ç®¡ç†';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documents`
--

LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'æ¥­å‹™ä»¶å',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'æ¥­å‹™æ¦‚è¦',
  `start_date` date DEFAULT NULL COMMENT 'å·¥äº‹ãƒ»æ¥­å‹™æœŸé–“(é–‹å§‹)',
  `end_date` date DEFAULT NULL COMMENT 'å·¥äº‹ãƒ»æ¥­å‹™æœŸé–“(çµ‚äº†)',
  `address` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'å·¥äº‹å ´æ‰€ãƒ»ä½æ‰€',
  `latitude` decimal(10,8) DEFAULT NULL COMMENT 'ç·¯åº¦ (Leaflet.jsç”¨)',
  `longitude` decimal(11,8) DEFAULT NULL COMMENT 'çµŒåº¦ (Leaflet.jsç”¨)',
  `created_by` bigint unsigned DEFAULT NULL COMMENT 'ä½œæˆè€…ãƒ¦ãƒ¼ã‚¶ãƒ¼ID',
  `status` enum('active','completed','on_hold') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_projects_created_by` (`created_by`),
  KEY `idx_project_date` (`start_date`,`end_date`),
  KEY `idx_geo` (`latitude`,`longitude`),
  KEY `idx_project_name` (`project_name`),
  KEY `idx_projects_status` (`status`),
  CONSTRAINT `fk_projects_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='æ¥­å‹™æ¡ˆä»¶ãŠã‚ˆã³ä½ç½®æƒ…å ±ç®¡ç†';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ãƒ­ã‚°ã‚¤ãƒ³ID',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP password_hashå€¤',
  `department` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'æ‰€å±žåˆ†é‡Ž (ä¾‹: åœ°è³ªåˆ†é‡Ž)',
  `role` enum('admin','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'æ¨©é™ãƒ¬ãƒ™ãƒ«',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `default_prompt` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'construction_consultant' COMMENT 'ãƒ‡ãƒ•ã‚©ãƒ«ãƒˆãƒ—ãƒ­ãƒ³ãƒ—ãƒˆã®è­˜åˆ¥å­',
  `default_lang` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'ja' COMMENT 'è¡¨ç¤ºè¨€èªžè¨­å®š',
  `default_model` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'gpt-oss:20b' COMMENT 'å„ªå…ˆä½¿ç”¨ãƒ¢ãƒ‡ãƒ«',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ãƒ¦ãƒ¼ã‚¶ãƒ¼èªè¨¼ãƒ»æ‰€å±žç®¡ç†';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
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

-- Dump completed on 2026-05-29 20:49:51
