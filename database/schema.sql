-- ============================================================
-- Krishi Sathi Research - database schema (structure only)
-- Target database: krishi_sathi_research_demo
-- Derived from the original research schema. NO production rows.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `krishi_sathi_research_demo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `krishi_sathi_research_demo`;

SET FOREIGN_KEY_CHECKS=0;


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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `location_districts`;
CREATE TABLE `location_districts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `province_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `province_id` (`province_id`),
  CONSTRAINT `location_districts_ibfk_1` FOREIGN KEY (`province_id`) REFERENCES `location_provinces` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `location_municipalities`;
CREATE TABLE `location_municipalities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `district_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `municipality_type` enum('Municipality','Rural Municipality','Sub-Metropolitan City','Metropolitan City') NOT NULL DEFAULT 'Municipality',
  PRIMARY KEY (`id`),
  KEY `district_id` (`district_id`),
  CONSTRAINT `location_municipalities_ibfk_1` FOREIGN KEY (`district_id`) REFERENCES `location_districts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=754 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `location_provinces`;
CREATE TABLE `location_provinces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `location_wards`;
CREATE TABLE `location_wards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `municipality_id` int(11) NOT NULL,
  `ward_number` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `municipality_id` (`municipality_id`),
  CONSTRAINT `location_wards_ibfk_1` FOREIGN KEY (`municipality_id`) REFERENCES `location_municipalities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6744 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_audit_log`;
CREATE TABLE `research_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=377 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_consent_records`;
CREATE TABLE `research_consent_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` int(11) DEFAULT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `consent_date` date NOT NULL,
  `consent_type` enum('farmer','stakeholder','photo','interview','audio') NOT NULL,
  `consent_status` enum('granted','withdrawn','expired') NOT NULL DEFAULT 'granted',
  `consent_method` enum('verbal','written_digital','written_physical','implied') DEFAULT NULL,
  `consent_form_filename` varchar(255) DEFAULT NULL COMMENT 'Uploaded consent form PDF/image',
  `consent_form_original_name` varchar(255) DEFAULT NULL,
  `irb_reference` varchar(100) DEFAULT NULL COMMENT 'IRB approval reference number',
  `researcher_id` int(11) NOT NULL,
  `witness_name` varchar(100) DEFAULT NULL COMMENT 'Witness for verbal consent',
  `witness_relationship` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdrawn_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `farmer_id` (`farmer_id`),
  KEY `participant_id` (`participant_id`),
  KEY `researcher_id` (`researcher_id`),
  KEY `withdrawn_by` (`withdrawn_by`),
  CONSTRAINT `research_consent_records_ibfk_1` FOREIGN KEY (`farmer_id`) REFERENCES `research_farmers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_consent_records_ibfk_2` FOREIGN KEY (`participant_id`) REFERENCES `research_participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_consent_records_ibfk_3` FOREIGN KEY (`researcher_id`) REFERENCES `research_users` (`id`),
  CONSTRAINT `research_consent_records_ibfk_4` FOREIGN KEY (`withdrawn_by`) REFERENCES `research_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_expenses`;
CREATE TABLE `research_expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `expense_location` varchar(255) DEFAULT NULL,
  `interview_batch_reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`expense_id`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_expense_category` (`category`),
  KEY `idx_expense_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_farm_profiles`;
CREATE TABLE `research_farm_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` int(11) NOT NULL,
  `farm_type` varchar(100) DEFAULT NULL,
  `farm_size` varchar(50) DEFAULT NULL,
  `land_unit` varchar(20) DEFAULT NULL,
  `years_farming` varchar(20) DEFAULT NULL,
  `is_commercial` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `farmer_id` (`farmer_id`),
  CONSTRAINT `research_farm_profiles_ibfk_1` FOREIGN KEY (`farmer_id`) REFERENCES `research_farmers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_farmers`;
CREATE TABLE `research_farmers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `ward` varchar(20) DEFAULT NULL,
  `tole` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `altitude` decimal(8,2) DEFAULT NULL,
  `gps_altitude` decimal(8,2) DEFAULT NULL,
  `gps_accuracy` decimal(10,2) DEFAULT NULL,
  `location_source` varchar(50) DEFAULT NULL,
  `approval_status` varchar(20) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `gps_timestamp` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `age_group` varchar(20) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `education_level` varchar(50) DEFAULT NULL,
  `primary_occupation` varchar(100) DEFAULT NULL,
  `land_ownership_type` varchar(50) DEFAULT NULL,
  `irrigation_access` varchar(50) DEFAULT NULL,
  `preferred_language` varchar(50) DEFAULT NULL,
  `cooperative_membership` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `farmer_id` (`farmer_id`),
  KEY `created_by` (`created_by`),
  KEY `research_farmers_deleted_by_fk` (`deleted_by`),
  KEY `idx_farmers_district` (`district`),
  KEY `idx_farmers_is_deleted` (`is_deleted`),
  CONSTRAINT `research_farmers_deleted_by_fk` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_farmers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_farmers_ibfk_2` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_guides`;
CREATE TABLE `research_guides` (
  `guide_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `download_count` int(11) DEFAULT 0,
  PRIMARY KEY (`guide_id`),
  KEY `idx_guide_category` (`category`),
  KEY `idx_guide_uploaded_by` (`uploaded_by`),
  KEY `idx_guide_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_interviews`;
CREATE TABLE `research_interviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` int(11) NOT NULL,
  `interview_date` date NOT NULL,
  `interviewer_id` int(11) NOT NULL,
  `interview_round` int(11) DEFAULT 1,
  `interview_mode` varchar(20) DEFAULT NULL,
  `interview_language` varchar(50) DEFAULT 'nepali',
  `location` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `altitude` decimal(8,2) DEFAULT NULL,
  `gps_altitude` decimal(8,2) DEFAULT NULL,
  `gps_accuracy` decimal(10,2) DEFAULT NULL,
  `location_source` varchar(50) DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `problems_selected` text DEFAULT NULL,
  `loss_contributing_causes` text DEFAULT NULL,
  `loss_cause` varchar(50) DEFAULT NULL,
  `loss_description` text DEFAULT NULL,
  `loss_amount` varchar(50) DEFAULT NULL,
  `loss_amount_npr` decimal(12,2) DEFAULT NULL,
  `loss_period` varchar(50) DEFAULT NULL,
  `record_keeping_method` varchar(100) DEFAULT NULL,
  `record_frequency` varchar(20) DEFAULT NULL,
  `technology_used` varchar(255) DEFAULT NULL,
  `smartphone_independence` varchar(30) DEFAULT NULL,
  `voice_interest` enum('yes','maybe','no') DEFAULT NULL,
  `voice_reason` text DEFAULT NULL,
  `voice_reason_options` text DEFAULT NULL,
  `voice_rejection_reasons` text DEFAULT NULL,
  `voice_use_cases` varchar(255) DEFAULT NULL,
  `assumption_reminders` varchar(10) DEFAULT NULL,
  `reminder_types` text DEFAULT NULL,
  `app_motivations` text DEFAULT NULL,
  `recommendation_types` text DEFAULT NULL,
  `interview_status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `follow_up_required` tinyint(1) DEFAULT 0,
  `follow_up_date` date DEFAULT NULL,
  `follow_up_reason` varchar(255) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlocked_by` int(11) DEFAULT NULL,
  `assumption_voice_app` varchar(10) DEFAULT NULL,
  `assumption_record_motivation` varchar(10) DEFAULT NULL,
  `assumption_personalized_recs` varchar(10) DEFAULT NULL,
  `interview_summary` text DEFAULT NULL,
  `important_findings` text DEFAULT NULL,
  `researcher_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `problem_severity` tinyint(4) DEFAULT NULL COMMENT '1-5 scale, higher = more severe',
  PRIMARY KEY (`id`),
  KEY `idx_interview_status` (`interview_status`),
  KEY `idx_farmer_id` (`farmer_id`),
  KEY `idx_interviewer_id` (`interviewer_id`),
  KEY `idx_voice_interest` (`voice_interest`),
  KEY `idx_follow_up` (`follow_up_required`,`follow_up_date`),
  KEY `idx_is_deleted` (`is_deleted`),
  CONSTRAINT `research_interviews_ibfk_1` FOREIGN KEY (`farmer_id`) REFERENCES `research_farmers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_interviews_ibfk_2` FOREIGN KEY (`interviewer_id`) REFERENCES `research_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_media`;
CREATE TABLE `research_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `interview_id` int(11) DEFAULT NULL,
  `observation_id` int(11) DEFAULT NULL,
  `farmer_id` int(11) DEFAULT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `media_type` enum('image','audio','video') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `stored_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `duration` varchar(20) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `consent` varchar(20) DEFAULT 'research',
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_media_interview` (`interview_id`),
  KEY `idx_media_observation` (`observation_id`),
  KEY `idx_media_farmer` (`farmer_id`),
  KEY `idx_media_participant` (`participant_id`),
  KEY `idx_media_type` (`media_type`),
  KEY `idx_media_uploaded_by` (`uploaded_by`),
  KEY `idx_media_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_observations`;
CREATE TABLE `research_observations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` int(11) DEFAULT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `interview_id` int(11) DEFAULT NULL,
  `observer_id` int(11) NOT NULL,
  `observation_date` date NOT NULL,
  `observed_smartphone_usage` text DEFAULT NULL,
  `observed_record_books` text DEFAULT NULL,
  `observed_technology` text DEFAULT NULL,
  `farm_condition` varchar(30) DEFAULT NULL,
  `farm_condition_details` text DEFAULT NULL,
  `crop_health` varchar(30) DEFAULT NULL,
  `pest_disease_presence` text DEFAULT NULL,
  `water_source` varchar(100) DEFAULT NULL,
  `researcher_notes` text DEFAULT NULL,
  `general_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `crop_health_assessment` enum('excellent','good','fair','poor') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `farmer_id` (`farmer_id`),
  KEY `interview_id` (`interview_id`),
  KEY `observer_id` (`observer_id`),
  KEY `research_observations_deleted_by_fk` (`deleted_by`),
  KEY `idx_observation_participant` (`participant_id`),
  CONSTRAINT `research_observations_deleted_by_fk` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_observations_ibfk_1` FOREIGN KEY (`farmer_id`) REFERENCES `research_farmers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_observations_ibfk_2` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_observations_ibfk_3` FOREIGN KEY (`observer_id`) REFERENCES `research_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_participant_interviews`;
CREATE TABLE `research_participant_interviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_id` int(11) NOT NULL,
  `interviewer_id` int(11) NOT NULL,
  `interview_date` date NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `altitude` decimal(8,2) DEFAULT NULL,
  `gps_altitude` decimal(8,2) DEFAULT NULL,
  `gps_accuracy` decimal(10,2) DEFAULT NULL,
  `location_source` varchar(50) DEFAULT NULL,
  `problems_observed` text DEFAULT NULL,
  `problems_increasing` text DEFAULT NULL,
  `problems_greatest_losses` text DEFAULT NULL,
  `hardest_decisions` text DEFAULT NULL,
  `decision_difficulty_reasons` text DEFAULT NULL,
  `info_sources` text DEFAULT NULL,
  `trusted_sources` text DEFAULT NULL,
  `info_arrives_late` text DEFAULT NULL,
  `record_keeping_methods` text DEFAULT NULL,
  `important_records` text DEFAULT NULL,
  `record_keeping_barriers` text DEFAULT NULL,
  `technologies_used` text DEFAULT NULL,
  `tech_adoption_barriers` text DEFAULT NULL,
  `voice_recording_useful` varchar(10) DEFAULT NULL,
  `voice_recording_uses` text DEFAULT NULL,
  `voice_recommendations_useful` varchar(10) DEFAULT NULL,
  `reminders_valuable` varchar(10) DEFAULT NULL,
  `useful_reminders` text DEFAULT NULL,
  `weekly_recs_useful` varchar(10) DEFAULT NULL,
  `valuable_recommendations` text DEFAULT NULL,
  `most_valuable_feature` text DEFAULT NULL,
  `interview_summary` text DEFAULT NULL,
  `major_findings` text DEFAULT NULL,
  `contradictions` text DEFAULT NULL,
  `new_research_opportunities` text DEFAULT NULL,
  `product_opportunities` text DEFAULT NULL,
  `recommended_action` text DEFAULT NULL,
  `research_importance` varchar(20) DEFAULT NULL,
  `interview_quality` varchar(20) DEFAULT NULL,
  `follow_up_needed` tinyint(1) DEFAULT 0,
  `follow_up_notes` text DEFAULT NULL,
  `interview_status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlocked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `current_step` int(11) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pi_participant` (`participant_id`),
  KEY `idx_pi_interviewer` (`interviewer_id`),
  KEY `idx_pi_status` (`interview_status`),
  KEY `idx_pi_date` (`interview_date`),
  KEY `idx_pi_voice` (`voice_recording_useful`),
  KEY `idx_pi_reminders` (`reminders_valuable`),
  KEY `idx_pi_deleted` (`is_deleted`,`deleted_at`),
  KEY `approved_by` (`approved_by`),
  KEY `submitted_by` (`submitted_by`),
  KEY `deleted_by` (`deleted_by`),
  CONSTRAINT `research_participant_interviews_ibfk_1` FOREIGN KEY (`participant_id`) REFERENCES `research_participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_participant_interviews_ibfk_2` FOREIGN KEY (`interviewer_id`) REFERENCES `research_users` (`id`),
  CONSTRAINT `research_participant_interviews_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_participant_interviews_ibfk_4` FOREIGN KEY (`submitted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_participant_interviews_ibfk_5` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_participant_photos`;
CREATE TABLE `research_participant_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `interview_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `consent` varchar(20) DEFAULT 'research',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpp_interview` (`interview_id`),
  CONSTRAINT `research_participant_photos_ibfk_1` FOREIGN KEY (`interview_id`) REFERENCES `research_participant_interviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_participant_responses`;
CREATE TABLE `research_participant_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `interview_id` int(11) NOT NULL,
  `question_group` varchar(50) NOT NULL,
  `question_key` varchar(100) NOT NULL,
  `response_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpr_interview` (`interview_id`),
  KEY `idx_rpr_group` (`question_group`),
  KEY `idx_rpr_key` (`question_key`),
  CONSTRAINT `research_participant_responses_ibfk_1` FOREIGN KEY (`interview_id`) REFERENCES `research_participant_interviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_participants`;
CREATE TABLE `research_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_id` varchar(50) NOT NULL,
  `participant_type` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `ward` varchar(20) DEFAULT NULL,
  `tole` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `altitude` decimal(8,2) DEFAULT NULL,
  `gps_altitude` decimal(8,2) DEFAULT NULL,
  `gps_accuracy` decimal(10,2) DEFAULT NULL,
  `gps_timestamp` timestamp NULL DEFAULT NULL,
  `location_source` varchar(50) DEFAULT NULL,
  `experience_years` varchar(30) DEFAULT NULL,
  `service_area` text DEFAULT NULL,
  `main_work_area` text DEFAULT NULL,
  `primary_role` varchar(255) DEFAULT NULL,
  `approval_status` varchar(20) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participant_id` (`participant_id`),
  KEY `idx_participant_type` (`participant_type`),
  KEY `idx_participant_district` (`district`),
  KEY `idx_participant_created_by` (`created_by`),
  KEY `idx_participant_approval` (`approval_status`),
  KEY `idx_participant_deleted` (`is_deleted`,`deleted_at`),
  KEY `approved_by` (`approved_by`),
  KEY `deleted_by` (`deleted_by`),
  CONSTRAINT `research_participants_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_participants_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_participants_ibfk_3` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_photos`;
CREATE TABLE `research_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `observation_id` int(11) DEFAULT NULL,
  `interview_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `consent` enum('research','internal','none') DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `observation_id` (`observation_id`),
  KEY `interview_id` (`interview_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `research_photos_ibfk_1` FOREIGN KEY (`observation_id`) REFERENCES `research_observations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_photos_ibfk_2` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_photos_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_photos_ibfk_4` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_photos_ibfk_5` FOREIGN KEY (`uploaded_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_photos_ibfk_6` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_photos_ibfk_7` FOREIGN KEY (`uploaded_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_photos_ibfk_8` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `research_photos_ibfk_9` FOREIGN KEY (`uploaded_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_problem_rankings`;
CREATE TABLE `research_problem_rankings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `interview_id` int(11) NOT NULL,
  `problem_number` int(11) NOT NULL,
  `problem_description` text DEFAULT NULL,
  `severity` tinyint(4) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_problem_interview` (`interview_id`),
  KEY `idx_problem_rank` (`problem_number`),
  CONSTRAINT `research_problem_rankings_ibfk_1` FOREIGN KEY (`interview_id`) REFERENCES `research_interviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=215 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_settings`;
CREATE TABLE `research_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `research_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `research_users`;
CREATE TABLE `research_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` enum('lead','editor','contributor','viewer') NOT NULL DEFAULT 'contributor',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `research_users_deleted_by_fk` (`deleted_by`),
  KEY `research_users_created_by_fk` (`created_by`),
  CONSTRAINT `research_users_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_users_deleted_by_fk` FOREIGN KEY (`deleted_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_users_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `research_users_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `research_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS=1;
