-- ============================================================
--  Blood Link — Database Schema (MySQL)
--  Project: AI-Powered Eligibility Screening & Donor Matching
--  Student: Saramsh Puri | LCID: LC00017002058
-- ============================================================

CREATE DATABASE IF NOT EXISTS bloodlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bloodlink;

-- ─────────────────────────────────────────────────────────────
-- USERS (base table for all roles)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('donor', 'hospital', 'admin') NOT NULL DEFAULT 'donor',
    phone           VARCHAR(25),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role  (role)
);

-- ─────────────────────────────────────────────────────────────
-- DONORS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE donors (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT UNSIGNED NOT NULL UNIQUE,
    blood_type              ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    gender                  ENUM('male','female','other') NOT NULL,
    date_of_birth           DATE NOT NULL,
    weight_kg               DECIMAL(5,2) NOT NULL,
    hemoglobin_gdl          DECIMAL(4,2) NOT NULL,
    address                 VARCHAR(255),
    city                    VARCHAR(100),
    latitude                DECIMAL(9,6),
    longitude               DECIMAL(9,6),
    last_donation_date      DATE,
    total_donations         SMALLINT UNSIGNED DEFAULT 0,
    is_eligible             TINYINT(1) DEFAULT 0,
    eligibility_score       DECIMAL(5,2) DEFAULT 0,
    eligibility_confidence  DECIMAL(4,3) DEFAULT 0,
    eligibility_checked_at  TIMESTAMP,
    eligibility_reasons     TEXT,        -- JSON: reasons array
    is_available            TINYINT(1) DEFAULT 1,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_blood_type    (blood_type),
    INDEX idx_eligible      (is_eligible),
    INDEX idx_location      (latitude, longitude)
);

-- Medical conditions (many-to-many)
CREATE TABLE donor_conditions (
    donor_id    INT UNSIGNED NOT NULL,
    condition   VARCHAR(50) NOT NULL,
    PRIMARY KEY (donor_id, condition),
    FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────────────────────
-- HOSPITALS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE hospitals (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    address     VARCHAR(255),
    city        VARCHAR(100),
    latitude    DECIMAL(9,6),
    longitude   DECIMAL(9,6),
    contact     VARCHAR(100),
    verified    TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_verified (verified),
    INDEX idx_location (latitude, longitude)
);

-- ─────────────────────────────────────────────────────────────
-- BLOOD INVENTORY
-- ─────────────────────────────────────────────────────────────
CREATE TABLE blood_inventory (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id     INT UNSIGNED NOT NULL,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_available SMALLINT UNSIGNED DEFAULT 0,
    units_reserved  SMALLINT UNSIGNED DEFAULT 0,
    expiry_date     DATE,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    UNIQUE KEY uq_hospital_type (hospital_id, blood_type),
    INDEX idx_blood_type (blood_type)
);

-- ─────────────────────────────────────────────────────────────
-- BLOOD REQUESTS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE blood_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id     INT UNSIGNED NOT NULL,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    units_fulfilled TINYINT UNSIGNED DEFAULT 0,
    urgency         ENUM('critical','urgent','moderate','scheduled') NOT NULL DEFAULT 'moderate',
    department      VARCHAR(100),
    notes           TEXT,
    status          ENUM('open','in_progress','fulfilled','cancelled','expired') DEFAULT 'open',
    expires_at      TIMESTAMP,
    created_by      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status     (status),
    INDEX idx_blood_type (blood_type),
    INDEX idx_urgency    (urgency),
    INDEX idx_created    (created_at)
);

-- ─────────────────────────────────────────────────────────────
-- DONOR-REQUEST MATCHES (AI matching results)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE donor_matches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,
    donor_id        INT UNSIGNED NOT NULL,
    match_score     DECIMAL(5,2) NOT NULL,
    distance_km     DECIMAL(6,2),
    blood_compat    ENUM('exact','compatible') DEFAULT 'exact',
    notified_at     TIMESTAMP,
    notify_channel  SET('email','sms') DEFAULT 'email',
    donor_response  ENUM('pending','accepted','declined','no_response') DEFAULT 'pending',
    responded_at    TIMESTAMP,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id)   REFERENCES donors(id) ON DELETE CASCADE,
    UNIQUE KEY uq_request_donor (request_id, donor_id),
    INDEX idx_request  (request_id),
    INDEX idx_donor    (donor_id),
    INDEX idx_response (donor_response)
);

-- ─────────────────────────────────────────────────────────────
-- DONATION HISTORY
-- ─────────────────────────────────────────────────────────────
CREATE TABLE donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id        INT UNSIGNED NOT NULL,
    hospital_id     INT UNSIGNED NOT NULL,
    request_id      INT UNSIGNED,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_donated   TINYINT UNSIGNED DEFAULT 1,
    donation_date   DATE NOT NULL,
    hemoglobin_at_donation DECIMAL(4,2),
    weight_at_donation     DECIMAL(5,2),
    notes           TEXT,
    status          ENUM('scheduled','completed','no_show','cancelled') DEFAULT 'completed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id)   REFERENCES donors(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id),
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON SET NULL,
    INDEX idx_donor      (donor_id),
    INDEX idx_date       (donation_date),
    INDEX idx_blood_type (blood_type)
);

-- ─────────────────────────────────────────────────────────────
-- NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('eligibility_alert','donation_reminder','match_request','request_fulfilled','system') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        TEXT NOT NULL,
    channel     ENUM('in_app','email','sms') DEFAULT 'in_app',
    is_read     TINYINT(1) DEFAULT 0,
    sent_at     TIMESTAMP,
    read_at     TIMESTAMP,
    meta        JSON,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user   (user_id),
    INDEX idx_unread (user_id, is_read),
    INDEX idx_type   (type)
);

-- ─────────────────────────────────────────────────────────────
-- JWT TOKENS (refresh token store)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE refresh_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    ip_address  VARCHAR(45),
    user_agent  VARCHAR(255),
    expires_at  TIMESTAMP NOT NULL,
    revoked     TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token   (token_hash),
    INDEX idx_user    (user_id),
    INDEX idx_expires (expires_at)
);

-- ─────────────────────────────────────────────────────────────
-- SYSTEM AUDIT LOG
-- ─────────────────────────────────────────────────────────────
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id   INT UNSIGNED,
    ip_address  VARCHAR(45),
    changes     JSON,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_action (action),
    INDEX idx_date   (created_at)
);

-- ─────────────────────────────────────────────────────────────
-- SAMPLE SEED DATA
-- ─────────────────────────────────────────────────────────────

-- Admin user (password: admin123 bcrypt hashed)
INSERT INTO users (name, email, password_hash, role, phone) VALUES
('Admin', 'admin@bloodlink.com', '$2y$12$examplehashhere', 'admin', NULL);

-- Sample donors
INSERT INTO users (name, email, password_hash, role, phone) VALUES
('Saramsh Puri',   'saramsh@demo.com',  '$2y$12$donorhashdemo1', 'donor', '+60-12-111-0001'),
('Aisha Rahman',   'aisha@demo.com',    '$2y$12$donorhashdemo2', 'donor', '+60-12-222-0002'),
('Kumar Raj',      'kumar@demo.com',    '$2y$12$donorhashdemo3', 'donor', '+60-12-333-0003'),
('Rachel Lim',     'rachel@demo.com',   '$2y$12$donorhashdemo4', 'donor', '+60-12-444-0004');

-- Sample hospitals
INSERT INTO users (name, email, password_hash, role, phone) VALUES
('Pantai Hospital', 'pantai@demo.com', '$2y$12$hosphashdemo1', 'hospital', '+60-3-2296-0888');

-- Donor profiles
INSERT INTO donors (user_id, blood_type, gender, date_of_birth, weight_kg, hemoglobin_gdl, city, latitude, longitude, last_donation_date, total_donations, is_eligible, eligibility_score) VALUES
(2, 'A+', 'male',   '2001-03-15', 68.0, 14.2, 'Kuala Lumpur', 3.1560, 101.7110, '2025-01-12', 7, 1, 100.0),
(3, 'O-', 'female', '1990-07-22', 62.5, 13.8, 'Petaling Jaya', 3.1065, 101.6071, '2024-10-18', 12, 1, 96.0),
(4, 'B+', 'male',   '1995-11-08', 75.0, 15.1, 'Shah Alam',    3.0738, 101.5183, '2025-02-10', 4, 0, 45.0),
(5, 'O-', 'female', '1988-04-30', 58.0, 13.2, 'Subang Jaya',  3.0523, 101.5806, '2024-09-05', 9, 1, 94.0);

-- Hospital
INSERT INTO hospitals (user_id, name, address, city, latitude, longitude, contact, verified) VALUES
(6, 'Pantai Hospital Kuala Lumpur', 'No 8 Jalan Bukit Pantai, Bangsar', 'Kuala Lumpur', 3.1478, 101.6953, 'frontdesk@pantai.com.my', 1);

-- Blood inventory for Pantai Hospital
INSERT INTO blood_inventory (hospital_id, blood_type, units_available, units_reserved) VALUES
(1,'A+',12,2),(1,'A-',3,0),(1,'B+',8,1),(1,'B-',1,0),
(1,'AB+',6,0),(1,'AB-',2,0),(1,'O+',15,3),(1,'O-',1,0);

-- Sample blood request
INSERT INTO blood_requests (hospital_id, blood_type, units_needed, urgency, department, status, created_by) VALUES
(1, 'A+', 3, 'critical', 'ICU', 'open', 6),
(1, 'O-', 2, 'critical', 'Emergency', 'open', 6);
