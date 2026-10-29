CREATE DATABASE IF NOT EXISTS broken_link_checker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE broken_link_checker;
CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','viewer') NOT NULL DEFAULT 'viewer', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT IGNORE INTO users(username,password_hash,role) VALUES ('admin','$2y$12$43mZR8SWB6qQg42EgREPhO/XbqbDeCG2lO4ZwxXrMMYr7hjQxDOaS','admin');
CREATE TABLE IF NOT EXISTS sites (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, start_url VARCHAR(500) NOT NULL,
 allowed_domains VARCHAR(500) NOT NULL, max_pages INT DEFAULT 25, max_depth INT DEFAULT 3,
 request_timeout DECIMAL(5,2) DEFAULT 3.00, max_duration DECIMAL(6,2) DEFAULT 30.00,
 allow_local TINYINT(1) DEFAULT 0, created_at VARCHAR(50) NOT NULL
);
CREATE TABLE IF NOT EXISTS scan_runs (
 id INT AUTO_INCREMENT PRIMARY KEY, site_id INT NOT NULL, started_at VARCHAR(50) NOT NULL,
 finished_at VARCHAR(50) NULL, status VARCHAR(50) NOT NULL, stop_reason VARCHAR(255) NULL,
 total_pages_crawled INT DEFAULT 0, total_links_found INT DEFAULT 0, total_errors INT DEFAULT 0,
 total_unchecked INT DEFAULT 0, FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS pages (
 id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, url VARCHAR(500) NOT NULL, depth INT NOT NULL,
 http_status INT NULL, title VARCHAR(255) NULL, anchors_list MEDIUMTEXT NULL, crawled_at VARCHAR(50) NOT NULL,
 FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE, INDEX(run_id), INDEX(url(190))
);
CREATE TABLE IF NOT EXISTS links (
 id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, source_page_id INT NOT NULL,
 source_url VARCHAR(500) NOT NULL, raw_href VARCHAR(500) NOT NULL, normalized_url VARCHAR(500) NOT NULL,
 base_target_url VARCHAR(500) NOT NULL, fragment VARCHAR(150) NULL, resource_type VARCHAR(30) NOT NULL,
 line_number INT NOT NULL, tag_snippet VARCHAR(300) NOT NULL, anchor_text VARCHAR(255) NULL,
 FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE,
 FOREIGN KEY(source_page_id) REFERENCES pages(id) ON DELETE CASCADE, INDEX(run_id), INDEX(normalized_url(190))
);
CREATE TABLE IF NOT EXISTS findings (
 id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, normalized_url VARCHAR(500) NOT NULL,
 base_target_url VARCHAR(500) NOT NULL, fragment VARCHAR(150) NULL, resource_type VARCHAR(30) NOT NULL,
 status_category VARCHAR(50) NOT NULL, http_status INT NULL, redirect_chain VARCHAR(500) NULL,
 error_detail VARCHAR(500) NOT NULL, checked_at VARCHAR(50) NOT NULL,
 FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE, INDEX(run_id), INDEX(normalized_url(190))
);
CREATE TABLE IF NOT EXISTS scan_schedules (
 id INT AUTO_INCREMENT PRIMARY KEY, site_id INT NOT NULL, interval_minutes INT NOT NULL DEFAULT 1440,
 enabled TINYINT(1) NOT NULL DEFAULT 1, next_run_at DATETIME NULL, last_run_at DATETIME NULL,
 FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS notifications (
 id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, message VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, is_read TINYINT(1) NOT NULL DEFAULT 0,
 FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE
);
