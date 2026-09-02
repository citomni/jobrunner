/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.4.9-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: citomni
-- ------------------------------------------------------
-- Server version	11.4.9-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `jobrun_jobs`
--

DROP TABLE IF EXISTS `jobrun_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobrun_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_uuid` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `job_type` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL,
  `claim_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lock_key` varchar(191) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `error_class` varchar(255) DEFAULT NULL,
  `error_reason_code` varchar(120) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `current_step_key` varchar(128) DEFAULT NULL,
  `current_step_label` varchar(255) DEFAULT NULL,
  `step_index` smallint(5) unsigned DEFAULT NULL,
  `step_total` smallint(5) unsigned DEFAULT NULL,
  `worker_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL,
  `queued_at` datetime(6) DEFAULT NULL,
  `started_at` datetime(6) DEFAULT NULL,
  `heartbeat_at` datetime(6) DEFAULT NULL,
  `finished_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) NOT NULL,
  `active_lock_key` varchar(191) GENERATED ALWAYS AS (case when `status` in ('queued','running','cancel_requested') and `lock_key` is not null and `lock_key` <> '' then `lock_key` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobrun_jobs_job_uuid` (`job_uuid`),
  UNIQUE KEY `uq_jobrun_jobs_active_lock_key` (`active_lock_key`),
  KEY `ix_jobrun_jobs_status_created` (`status`,`created_at`),
  KEY `ix_jobrun_jobs_type_status` (`job_type`,`status`),
  KEY `ix_jobrun_jobs_lock_status` (`lock_key`,`status`),
  KEY `ix_jobrun_jobs_status_heartbeat` (`status`,`heartbeat_at`),
  KEY `ix_jobrun_jobs_claim_status_created` (`claim_mode`,`status`,`created_at`,`id`),
  CONSTRAINT `chk_jobrun_jobs_claim_mode` CHECK (`claim_mode` in ('token','trusted')),
  CONSTRAINT `chk_jobrun_jobs_token_hash` CHECK (`claim_mode` <> 'token' or `worker_token_hash` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobrun_logs`
--

DROP TABLE IF EXISTS `jobrun_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobrun_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint(20) unsigned NOT NULL,
  `seq` int(10) unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `level` varchar(32) NOT NULL,
  `stream` varchar(32) NOT NULL DEFAULT '',
  `step_key` varchar(128) DEFAULT NULL,
  `message` mediumtext NOT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobrun_logs_job_seq` (`job_id`,`seq`),
  KEY `ix_jobrun_logs_job_created` (`job_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-02 13:21:53
