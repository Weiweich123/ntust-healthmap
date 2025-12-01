-- @dialect MySQL
-- MySQL/MariaDB Schema for NTUST Health Map

CREATE DATABASE IF NOT EXISTS `ntust_healthmap` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ntust_healthmap`;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(150) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `points` INT DEFAULT 0,
  `money` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teams` (
  `team_id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(32) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `team_members` (
  `team_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` VARCHAR(20) DEFAULT 'member',
  PRIMARY KEY (`team_id`,`user_id`),
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`team_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `buildings` (
  `building_id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `description` TEXT,
  `unlock_cost` INT NOT NULL DEFAULT 10,
  `reward_money` INT NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_buildings` (
  `user_id` INT NOT NULL,
  `building_id` INT NOT NULL,
  `level` INT NOT NULL DEFAULT 0,
  `unlocked_at` TIMESTAMP NULL,
  PRIMARY KEY (`user_id`,`building_id`),
  CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ub_building` FOREIGN KEY (`building_id`) REFERENCES `buildings`(`building_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activities` (
  `activity_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `activity_date` DATE NOT NULL,
  `steps` INT DEFAULT 0,
  `time_minutes` INT DEFAULT 0,
  `water_ml` INT DEFAULT 0,
  `points_earned` INT DEFAULT 0,
  `money_earned` INT DEFAULT 0,
  `created_at` DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT `fk_act_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  KEY `idx_user_date` (`user_id`,`activity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `team_tasks` (
  `task_id` INT AUTO_INCREMENT PRIMARY KEY,
  `team_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `points` INT NOT NULL DEFAULT 10,
  `completed_by` INT DEFAULT NULL,
  `completed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_team_created` (`team_id`, `created_at`),
  CONSTRAINT `fk_tt_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`team_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_user` FOREIGN KEY (`completed_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `task_confirmations` (
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `confirmed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`, `user_id`),
  CONSTRAINT `fk_tc_task` FOREIGN KEY (`task_id`) REFERENCES `team_tasks`(`task_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `buildings` (`building_id`,`name`,`lat`,`lng`,`description`,`unlock_cost`,`reward_money`) VALUES
(1,'AAEON Building (研揚大樓)',25.0150423,121.5427957,'研揚大樓 (AAEON Building)',20,10),
(2,'醫揚大樓',25.0146042,121.5431905,'醫揚大樓',18,9),
(3,'NTUST Library',25.0138160,121.5411600,'圖書館，提供學習與借閱資源。',25,15),
(4,'Student Center',25.0138587,121.5427596,'學生活動中心與餐飲。',12,6),
(5,'Gymnasium',25.0141738,121.5432091,'體育館與運動設施。',10,5),
(6,'Administrative Building (行政大樓)',25.0132311,121.5410809,'行政大樓',22,12),
(7,'Engineering Building 1 (工程一館)',25.0133488,121.5421525,'工程一館',18,9),
(8,'Teaching Building 1 (第一教學大樓)',25.0143440,121.5417688,'第一教學大樓',16,8),
(9,'Teaching Building 2 (第二教學大樓)',25.0120734,121.5408841,'第二教學大樓',16,8),
(10,'Teaching Building 3 (第三教學大樓)',25.0135006,121.5429062,'第三教學大樓',16,8),
(11,'Teaching Building 4 (第四教學大樓)',25.0138763,121.5417739,'第四教學大樓',16,8),
(12,'International Building (國際大樓)',25.0123421,121.5395572,'國際大樓',24,12),
(13,'Engineering Building 2 (工程二館)',25.0125296,121.5407173,'工程二館',18,9),
(14,'EE/CS Building (電資館)',25.0118944,121.5413746,'電資館',18,9),
(15,'Management Building (管理大樓)',25.0123924,121.5412578,'管理大樓',20,10),
(17,'Research Complex (綜合研究大樓)',25.0142332,121.5413767,'綜合研究大樓',20,10)
ON DUPLICATE KEY UPDATE name=VALUES(name), lat=VALUES(lat), lng=VALUES(lng), description=VALUES(description), unlock_cost=VALUES(unlock_cost), reward_money=VALUES(reward_money);
-- remove unwanted buildings requested by user (if present)
DELETE FROM buildings WHERE building_id IN (16,18,19,20);

-- ============================================================================
-- 好友系統資料表
-- ============================================================================
CREATE TABLE IF NOT EXISTS `friendships` (
  `friendship_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `friend_id` INT NOT NULL,
  `status` ENUM('pending','accepted','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_friendship` (`user_id`, `friend_id`),
  CONSTRAINT `fk_fr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_friend` FOREIGN KEY (`friend_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 團隊邀請資料表
-- ============================================================================
CREATE TABLE IF NOT EXISTS `team_invites` (
  `invite_id` INT AUTO_INCREMENT PRIMARY KEY,
  `team_id` INT NOT NULL,
  `inviter_id` INT NOT NULL,
  `invitee_id` INT NOT NULL,
  `status` ENUM('pending','accepted','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_invite` (`team_id`, `invitee_id`, `status`),
  CONSTRAINT `fk_ti_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`team_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ti_inviter` FOREIGN KEY (`inviter_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ti_invitee` FOREIGN KEY (`invitee_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- MIGRATION INSTRUCTIONS: Run these commands in phpMyAdmin SQL tab to migrate existing DB
-- (Remove the -- comments before each line to execute, or copy the uncommented version below)
-- ============================================================================
--
-- STEP 1: Backup your current activities table (recommended)
-- CREATE TABLE activities_backup AS SELECT * FROM activities;
--
-- STEP 2: Create new table with activity_id primary key
-- CREATE TABLE activities_new (
--   activity_id INT AUTO_INCREMENT PRIMARY KEY,
--   user_id INT NOT NULL,
--   activity_date DATE NOT NULL,
--   steps INT DEFAULT 0,
--   time_minutes INT DEFAULT 0,
--   water_ml INT DEFAULT 0,
--   points_earned INT DEFAULT 0,
--   money_earned INT DEFAULT 0,
--   created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
--   KEY idx_user_date (user_id, activity_date),
--   CONSTRAINT fk_act_user_new FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- STEP 3: Copy all data from old table to new table
-- INSERT INTO activities_new (user_id, activity_date, steps, time_minutes, water_ml, points_earned, money_earned, created_at)
-- SELECT user_id, activity_date, steps, time_minutes, water_ml, points_earned, money_earned, created_at FROM activities;
--
-- STEP 4: Verify record counts match
-- SELECT COUNT(*) AS old_count FROM activities;
-- SELECT COUNT(*) AS new_count FROM activities_new;
--
-- STEP 5: If counts match, swap the tables
-- RENAME TABLE activities TO activities_old, activities_new TO activities;
--
-- STEP 6: (Optional) After verifying everything works, drop old table
-- DROP TABLE activities_old;
-- DROP TABLE activities_backup;

-- ============================================================================
-- MIGRATION: Add birth_date column to existing users table
-- ============================================================================
-- ALTER TABLE users ADD COLUMN birth_date DATE DEFAULT NULL AFTER display_name;

-- ============================================================================
-- 金錢紀錄資料表（記錄所有金錢變動）
-- ============================================================================
CREATE TABLE IF NOT EXISTS `money_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount` INT NOT NULL,
  `source` VARCHAR(50) NOT NULL COMMENT 'building_unlock, building_upgrade',
  `description` VARCHAR(255) DEFAULT NULL,
  `related_id` INT DEFAULT NULL COMMENT '相關ID（如building_id）',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ml_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  KEY `idx_user_date` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 點數紀錄資料表（記錄所有點數變動，包括活動、團隊獎勵等）
-- ============================================================================
CREATE TABLE IF NOT EXISTS `points_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount` INT NOT NULL COMMENT '點數變動量（正數為獲得，負數為消耗）',
  `source` VARCHAR(50) NOT NULL COMMENT 'activity, team_bonus, team_task, other',
  `description` VARCHAR(255) DEFAULT NULL,
  `related_id` INT DEFAULT NULL COMMENT '相關ID（如activity_id, team_id）',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_date` (`user_id`, `created_at`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 好友聊天訊息資料表
-- ============================================================================
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `message_id` INT AUTO_INCREMENT PRIMARY KEY,
  `sender_id` INT NOT NULL,
  `receiver_id` INT NOT NULL,
  `message_type` ENUM('text', 'voice') DEFAULT 'text' COMMENT '訊息類型：文字或語音',
  `content` TEXT NOT NULL COMMENT '文字訊息內容或語音檔案路徑',
  `is_read` TINYINT(1) DEFAULT 0 COMMENT '是否已讀：0=未讀, 1=已讀',
  `read_at` TIMESTAMP NULL COMMENT '已讀時間',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cm_sender` FOREIGN KEY (`sender_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  KEY `idx_sender_receiver` (`sender_id`, `receiver_id`),
  KEY `idx_receiver_unread` (`receiver_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
