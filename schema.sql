
CREATE DATABASE IF NOT EXISTS `ntust_healthmap` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ntust_healthmap`;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(150) DEFAULT NULL,
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
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_act_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  INDEX (`user_id`),
  INDEX (`activity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `team_tasks` (
  `team_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `points` INT NOT NULL DEFAULT 10,
  `completed_by` INT DEFAULT NULL,
  `completed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tt_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`team_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_user` FOREIGN KEY (`completed_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  PRIMARY KEY (`team_id`,`created_at`)
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
