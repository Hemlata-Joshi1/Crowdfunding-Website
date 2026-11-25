-- Database schema additions for FundRaise Crowdfunding Platform
-- Run against `crowdfunding_db`

ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'donor', 'creator', 'admin') NOT NULL DEFAULT 'user';

ALTER TABLE campaigns
    MODIFY COLUMN status ENUM('pending', 'active', 'paused', 'closed', 'rejected') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS campaign_evidences (
    id INT(11) NOT NULL AUTO_INCREMENT,
    campaign_id INT(11) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
    PRIMARY KEY (id),
    KEY campaign_id (campaign_id),
    CONSTRAINT campaign_evidences_ibfk_1 FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_key VARCHAR(64) NOT NULL,
    attempts INT(11) NOT NULL DEFAULT 0,
    locked_until DATETIME NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    PRIMARY KEY (attempt_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
