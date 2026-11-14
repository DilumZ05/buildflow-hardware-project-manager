-- BuildFlow MySQL / MariaDB Schema & Seed Data (XAMPP / phpMyAdmin Ready)
-- Import this file via phpMyAdmin (http://localhost/phpmyadmin)

CREATE DATABASE IF NOT EXISTS `buildflow`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `buildflow`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `requests`;
DROP TABLE IF EXISTS `expenses`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `contribution_requests`;
DROP TABLE IF EXISTS `components`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `meeting_attendance`;
DROP TABLE IF EXISTS `meetings`;
DROP TABLE IF EXISTS `task_attachments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `project_members`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(160) NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `avatar_color` VARCHAR(16) NOT NULL DEFAULT '#2563EB',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_code` VARCHAR(32) NOT NULL,
  `room_name` VARCHAR(160) NOT NULL,
  `project_name` VARCHAR(200) NOT NULL,
  `room_password_hash` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `max_members` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `planned_budget` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` ENUM('LKR', 'USD') NOT NULL DEFAULT 'LKR',
  `expected_completion_date` VARCHAR(32) NULL,
  `leader_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_room_code` (`room_code`),
  UNIQUE KEY `uq_projects_room_name` (`room_name`),
  CONSTRAINT `fk_projects_leader` FOREIGN KEY (`leader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('leader', 'member') NOT NULL DEFAULT 'member',
  `responsibilities` TEXT NOT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_member` (`project_id`, `user_id`),
  KEY `idx_project_members_user` (`user_id`),
  CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `assigned_to` INT UNSIGNED NULL,
  `priority` ENUM('Low', 'Medium', 'High') NOT NULL DEFAULT 'Medium',
  `start_date` VARCHAR(32) NULL,
  `deadline` VARCHAR(32) NULL,
  `status` ENUM('To Do', 'In Progress', 'Completed') NOT NULL DEFAULT 'To Do',
  `leader_feedback` TEXT NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_project` (`project_id`),
  CONSTRAINT `fk_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `task_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` INT UNSIGNED NOT NULL,
  `project_id` INT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(160) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ta_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `meetings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `meeting_date` VARCHAR(32) NOT NULL,
  `start_time` VARCHAR(32) NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `agenda` TEXT NOT NULL,
  `status` ENUM('Scheduled', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
  `notes` TEXT NOT NULL,
  `decisions` TEXT NOT NULL,
  `action_items` TEXT NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_meetings_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_meetings_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `meeting_attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meeting_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('Going', 'Unable', 'Pending') NOT NULL DEFAULT 'Pending',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meeting_user` (`meeting_id`, `user_id`),
  CONSTRAINT `fk_ma_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ma_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `announcements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ann_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(120) NOT NULL,
  `description` TEXT NOT NULL,
  `qty_required` INT NOT NULL DEFAULT 1,
  `qty_available` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `supplier` VARCHAR(200) NOT NULL,
  `purchase_status` ENUM('Not Purchased', 'Ordered', 'Available') NOT NULL DEFAULT 'Not Purchased',
  `notes` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_components_project` (`project_id`),
  CONSTRAINT `fk_comp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contribution_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `instructions` TEXT NOT NULL,
  `deadline` VARCHAR(32) NULL,
  `status` ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_cr_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contribution_id` INT UNSIGNED NOT NULL,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `expected_amount` DECIMAL(12,2) NOT NULL,
  `submitted_amount` DECIMAL(12,2) NULL,
  `receipt_stored_name` VARCHAR(255) NULL,
  `receipt_original_name` VARCHAR(255) NULL,
  `receipt_mime` VARCHAR(160) NULL,
  `status` ENUM('Not Submitted', 'Submitted', 'Verified', 'Rejected') NOT NULL DEFAULT 'Not Submitted',
  `rejection_reason` TEXT NOT NULL,
  `submitted_at` DATETIME NULL,
  `verified_at` DATETIME NULL,
  `verified_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_contrib_user` (`contribution_id`, `user_id`),
  KEY `idx_payments_project` (`project_id`),
  CONSTRAINT `fk_pay_contrib` FOREIGN KEY (`contribution_id`) REFERENCES `contribution_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `purchase_date` VARCHAR(32) NOT NULL,
  `purchased_by` INT UNSIGNED NOT NULL,
  `receipt_stored_name` VARCHAR(255) NULL,
  `receipt_original_name` VARCHAR(255) NULL,
  `receipt_mime` VARCHAR(160) NULL,
  `status` ENUM('Pending', 'Approved', 'Rejected', 'Archived') NOT NULL DEFAULT 'Pending',
  `reviewed_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_exp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exp_purchaser` FOREIGN KEY (`purchased_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exp_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `category` VARCHAR(120) NOT NULL DEFAULT 'Hardware Component',
  `priority` ENUM('Low', 'Medium', 'High') NOT NULL DEFAULT 'Medium',
  `status` ENUM('Pending', 'Approved', 'Rejected', 'In Progress', 'Completed') NOT NULL DEFAULT 'Pending',
  `requested_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_req_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_req_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `attachment_stored_name` VARCHAR(255) NULL,
  `attachment_original_name` VARCHAR(255) NULL,
  `attachment_mime` VARCHAR(160) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_messages_project` (`project_id`),
  CONSTRAINT `fk_msg_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(120) NOT NULL DEFAULT 'Documentation',
  `stored_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(160) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_doc_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `project_id`),
  CONSTRAINT `fk_notif_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `action_text` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_act_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_act_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== SEEDED DEMO DATA ====================
-- Demo User Passwords: demo1234
-- Room Passwords: BF-ROVR04 => rover2026 | BF-HYDR02 => hydro2026

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `password_hash`, `avatar_color`) VALUES
(1, 'Alex Chen', 'alexchen', 'alex.chen@uni.edu', 'sha256:4478ef5ad344763f292cdd03e305018f:222195a7b75a6382f7b8a6853169d7e109d30503588cb400c84bde2cb958a01e', '#2563EB'),
(2, 'Maya Patel', 'mayapatel', 'maya.patel@uni.edu', 'sha256:4478ef5ad344763f292cdd03e305018f:222195a7b75a6382f7b8a6853169d7e109d30503588cb400c84bde2cb958a01e', '#7C3AED'),
(3, 'Liam Silva', 'liamsilva', 'liam.silva@uni.edu', 'sha256:4478ef5ad344763f292cdd03e305018f:222195a7b75a6382f7b8a6853169d7e109d30503588cb400c84bde2cb958a01e', '#16A34A');

INSERT INTO `projects` (`id`, `room_code`, `room_name`, `project_name`, `room_password_hash`, `description`, `max_members`, `planned_budget`, `currency`, `expected_completion_date`, `leader_id`) VALUES
(1, 'BF-ROVR04', 'Robotics Lab 04', 'Autonomous Campus Rover', 'sha256:a02575f6b764f9fa7489fea2e1048e98:f53cc58f5ece1702f1ea37bf10ce385701312b55e4650f7bb28494ba01e6d1b7', 'Senior capstone 4WD ROS2 rover with LiDAR obstacle avoidance, custom power distribution PCB, and telemetry dashboard.', 4, 150000.00, 'LKR', '2027-04-15', 1),
(2, 'BF-HYDR02', 'IoT Greenhouse', 'Smart Hydroponic Controller', 'sha256:b79ef7e70d7b7a3bb3535645a47f10ea:9196447bd991e689edc005fe87fed282ab9b42e64dbae7777532a1133cedfb4d', 'Automated pH/EC dosing controller using ESP8266, peristaltic pumps, and solar battery backup.', 3, 95000.00, 'LKR', '2027-02-28', 2);

INSERT INTO `project_members` (`project_id`, `user_id`, `role`, `responsibilities`) VALUES
(1, 1, 'leader', 'System Architecture, Embedded Firmware & Budget Lead'),
(1, 2, 'member', 'Power PCB Design & Sensor Integration'),
(1, 3, 'member', 'Chassis CAD, Drivetrain & Mechanical Assembly'),
(2, 2, 'leader', 'Sensor Calibration & Cloud Telemetry'),
(2, 1, 'member', 'Relay Driver Board & Enclosure');

INSERT INTO `tasks` (`project_id`, `title`, `description`, `assigned_to`, `priority`, `start_date`, `deadline`, `status`, `leader_feedback`, `created_by`) VALUES
(1, 'Design 12V-to-5V Buck Converter PCB', 'Route dual-layer KiCad schematic with TPS54302 buck regulator and reverse-polarity MOSFET guard.', 2, 'High', '2026-11-01', '2026-11-20', 'Completed', 'Gerber files look clean. Approved for JLCPCB batch order.', 1),
(1, 'Assemble Aluminum 4WD Chassis Frame', 'Cut 2020 aluminum extrusions and mount NEMA 17 brackets with M4 vibration dampers.', 3, 'High', '2026-11-05', '2026-11-24', 'In Progress', '', 1),
(1, 'Calibrate ESP32 Motor PWM PID Loop', 'Tune wheel encoder feedback loop at 100Hz over UART bridge.', 1, 'Medium', '2026-11-10', '2026-11-30', 'In Progress', '', 1),
(1, 'Integrate RPLIDAR A1 ROS2 Driver', 'Validate 360-degree point cloud topics inside lab test corridor.', 2, 'Medium', '2026-11-18', '2026-12-08', 'To Do', '', 1),
(2, 'Calibrate Analog pH Probe Buffer', 'Test pH 4.0 and pH 7.0 reference solutions and store linear curve constants.', 2, 'High', '2026-11-04', '2026-11-19', 'Completed', '', 2),
(2, 'Wire 4-Channel Opto-Isolated Relay', 'Connect 12V peristaltic dosing pumps with flyback protection diodes.', 1, 'Medium', '2026-11-12', '2026-11-29', 'To Do', '', 2);

INSERT INTO `components` (`project_id`, `name`, `category`, `description`, `qty_required`, `qty_available`, `unit_price`, `supplier`, `purchase_status`, `notes`) VALUES
(1, 'ESP32-S3 DevKitC-1', 'Microcontrollers', 'Dual-core Wi-Fi/BLE MCU for motor telemetry and sensor fusion', 2, 2, 3450.00, 'Duino.lk / Colombo', 'Available', 'Tested and flashed with base firmware'),
(1, 'NEMA 17 Stepper Motor (1.5A)', 'Motors', '42x40mm high-torque bipolar stepper for rover wheels', 4, 2, 5400.00, 'Tronic.lk', 'Ordered', '2 units arrived; 2 more shipping this week'),
(1, 'TB6600 Stepper Driver', 'Motor drivers', '4A microstepping driver module for NEMA 17 motors', 4, 0, 3750.00, 'LankaTronics', 'Not Purchased', 'Waiting on contribution verification'),
(1, 'RPLIDAR A1M8 360° Scanner', 'Sensors', '12m range 2D laser scanner for SLAM navigation', 1, 1, 29500.00, 'Dept. Lab Loan', 'Available', 'Borrowed from Mechatronics Lab cabinet B'),
(2, 'DFRobot Analog pH Sensor Kit', 'Sensors', 'Gravity BNC pH probe + signal conditioning board', 1, 1, 11400.00, 'DFRobot', 'Available', 'Calibrated in lab');

INSERT INTO `meetings` (`id`, `project_id`, `title`, `description`, `meeting_date`, `start_time`, `location`, `agenda`, `status`, `notes`, `decisions`, `action_items`, `created_by`) VALUES
(1, 1, 'Chassis & Power Rail Integration Review', 'Inspect aluminum frame tolerances and verify bench power supply load test before PCB assembly.', '2026-11-22', '14:30', 'Engineering Building · Lab 304', '1. Review buck converter ripple test\n2. Confirm motor mount alignment\n3. Finalize TB6600 purchase order', 'Scheduled', '', '', '', 1),
(2, 1, 'Kickoff & Bill of Materials Freeze', 'Initial semester planning session and component budgeting.', '2026-11-02', '16:00', 'Library Study Pod 2B', 'Define rover weight budget and sensor interface voltages.', 'Completed', 'Agreed on 12V LiFePO4 battery pack for safer indoor lab testing.', 'Selected ESP32-S3 + TB6600 driver architecture; capped Phase 1 BOM at Rs. 95,000.', 'Maya: Finalize power PCB layout; Liam: Cut 2020 rails.', 1);

INSERT INTO `meeting_attendance` (`meeting_id`, `user_id`, `status`) VALUES
(1, 1, 'Going'),
(1, 2, 'Going'),
(1, 3, 'Pending');

INSERT INTO `announcements` (`project_id`, `title`, `content`, `created_by`) VALUES
(1, 'Lab Safety Sign-Off Completed', 'Soldering station 4 and battery charging cabinet are cleared for our team every Tuesday and Thursday afternoon.', 1);

INSERT INTO `contribution_requests` (`id`, `project_id`, `title`, `description`, `amount`, `instructions`, `deadline`, `status`, `created_by`) VALUES
(1, 1, 'Phase 1 Drivetrain & PCB Fund', 'Shared team pool to cover stepper motors, drivers, and PCB fabrication.', 15000.00, 'Transfer to team treasury account (Ref: ROVR04-P1) and upload transfer screenshot or PDF.', '2026-11-25', 'Active', 1),
(2, 1, 'Phase 2 LiDAR & Wiring Pool', 'Shared pool for silicone power harnesses, XT60 connectors, and LiDAR mounting bracket.', 8500.00, 'Send to team treasury account (Ref: ROVR04-P2) and attach transfer receipt.', '2026-12-05', 'Active', 1);

INSERT INTO `payments` (`contribution_id`, `project_id`, `user_id`, `expected_amount`, `submitted_amount`, `receipt_stored_name`, `receipt_original_name`, `receipt_mime`, `status`, `rejection_reason`, `submitted_at`, `verified_at`, `verified_by`) VALUES
(1, 1, 2, 15000.00, 15000.00, NULL, NULL, NULL, 'Verified', '', '2026-11-08 10:15:00', '2026-11-08 14:20:00', 1),
(1, 1, 3, 15000.00, NULL, NULL, NULL, NULL, 'Not Submitted', '', NULL, NULL, NULL),
(2, 1, 3, 8500.00, 8500.00, 'demo-liam-receipt.pdf', 'liam-transfer-receipt.pdf', 'application/pdf', 'Submitted', '', DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, NULL),
(2, 1, 2, 8500.00, NULL, NULL, NULL, NULL, 'Not Submitted', '', NULL, NULL, NULL);

INSERT INTO `expenses` (`project_id`, `item_name`, `description`, `amount`, `purchase_date`, `purchased_by`, `status`, `reviewed_by`) VALUES
(1, '2x NEMA 17 Stepper Motors + Couplers', 'First batch mechanical drive motors ordered via Tronic.lk.', 11400.00, '2026-11-09', 2, 'Approved', 1);

INSERT INTO `requests` (`project_id`, `title`, `description`, `category`, `priority`, `status`, `requested_by`) VALUES
(1, 'Order 4x TB6600 Stepper Drivers', 'Current breadboard drivers overheat above 1.2A; need enclosed TB6600 modules before chassis testing.', 'Hardware Component', 'High', 'Approved', 3);

INSERT INTO `messages` (`project_id`, `user_id`, `body`) VALUES
(1, 1, 'Welcome to the Robotics Lab 04 room! Power PCB gerbers are verified—let us keep motor mount updates here.'),
(1, 2, 'Sounds good! Buck converter components arrive tomorrow afternoon.');

INSERT INTO `activity_logs` (`project_id`, `user_id`, `action_text`) VALUES
(1, 1, 'Alex Chen created project room "Robotics Lab 04"'),
(1, 1, 'Alex Chen verified Maya Patel payment (Rs. 15,000.00)'),
(1, 2, 'Maya Patel completed task "Design 12V-to-5V Buck Converter PCB"'),
(1, 3, 'Liam Silva submitted request "Order 4x TB6600 Stepper Drivers"'),
(2, 2, 'Maya Patel created project room "IoT Greenhouse"');

INSERT INTO `notifications` (`project_id`, `user_id`, `title`, `message`, `is_read`) VALUES
(1, 2, 'Payment Verified', 'Your Rs. 15,000.00 contribution for Phase 1 Drivetrain & PCB Fund was verified.', 0),
(1, 3, 'New Contribution Request', 'Phase 1 Drivetrain & PCB Fund (Rs. 15,000.00) is due by 2026-11-25.', 0),
(1, 1, 'Receipt Submitted', 'Liam Silva uploaded a payment receipt (Rs. 8,500.00) for Phase 2 LiDAR & Wiring Pool.', 0);
