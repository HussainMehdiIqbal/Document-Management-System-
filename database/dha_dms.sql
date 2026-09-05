-- ============================================================
-- DHA Document Management System - FRESH Database Schema
-- Database: dms_database
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
--
-- This is a CLEAN rebuild — it includes everything the app actually
-- needs (assigned_branch, the Validator role, rename_meta, source),
-- none of which were in the original shipped schema even though the
-- live app depended on them. Only the Super Administrator is seeded;
-- no other users, documents, folders, or activity.
--
-- role_id 4 ("Renamer") is a guess based on what the app's access
-- checks require — rename that row below if it's actually called
-- something else.
-- ============================================================

DROP DATABASE IF EXISTS dms_database;
CREATE DATABASE dms_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dms_database;

-- -----------------------------------------------------
-- Table: roles
-- -----------------------------------------------------
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL,
    description VARCHAR(250),
    PRIMARY KEY (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (role_id, role_name, description) VALUES
(1, 'Admin',     'Full system access — manage users, validate, and view all reports'),
(2, 'Scanner',   'Can scan documents'),
(3, 'Validator', 'Can validate (approve/change) documents'),
(4, 'Renamer',   'Can rename documents'); -- rename this row if role_id 4 has a different name in your app

-- -----------------------------------------------------
-- Table: users
-- -----------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    role_id INT,
    -- Comma-separated subset of: Transfer, Building_Control, Land_Acquisition.
    -- Empty/NULL = unrestricted (sees all branches). One value = locked to
    -- it. Multiple values = restricted dropdown of just those.
    assigned_branch VARCHAR(100) NULL,
    avatar_color VARCHAR(20) DEFAULT '#1a8a50',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles(role_id) ON DELETE SET NULL,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Only seed: Super Admin. Username: admin / Password: admin123
-- CHANGE THIS PASSWORD immediately after your first login.
INSERT INTO users (username, password_hash, first_name, last_name, full_name, employee_id, role_id, assigned_branch, avatar_color, status) VALUES
('admin', '$2y$10$bkCmy9d1uMwuFcZ82Ar7l.pNkwOHENy7Y5iLvPkRaXe0ETVOn5fWi', 'Super', 'Administrator', 'Super Administrator', 'EMP-1001', 1, NULL, '#0a4a2a', 'active');

-- -----------------------------------------------------
-- Table: user_dashboard_resets
-- Powers the "Reset Dashboard" button (per-user cutoff for daily KPIs).
-- -----------------------------------------------------
CREATE TABLE user_dashboard_resets (
    user_id INT NOT NULL,
    reset_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_dashboard_resets_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Table: folders
-- -----------------------------------------------------
CREATE TABLE folders (
    folder_id INT AUTO_INCREMENT,
    folder_name VARCHAR(150) NOT NULL,
    created_by INT,
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_to_validation_at DATETIME,
    PRIMARY KEY (folder_id),
    CONSTRAINT fk_folders_creator FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Table: documents
-- -----------------------------------------------------
CREATE TABLE documents (
    document_id INT AUTO_INCREMENT,
    raw_filename VARCHAR(255) NOT NULL,
    renamed_filename VARCHAR(255),
    file_type VARCHAR(20),
    file_size VARCHAR(20),
    storage_path VARCHAR(500),
    branch VARCHAR(50),
    doc_type VARCHAR(50),
    file_no VARCHAR(50),
    phase VARCHAR(50),
    plot VARCHAR(50),
    doc_year VARCHAR(10),
    status ENUM('pending', 'approved', 'changed', 'awaiting_rename') DEFAULT 'pending',
    folder_id INT,
    scanned_by INT,
    scanned_at DATETIME,
    -- 'scan' = actually scanned/uploaded via the Scan module; 'browse' =
    -- discovered while browsing a local folder in Rename; 'upload' = added
    -- via Folders' file upload. Only 'scan' counts toward Scan KPIs.
    source ENUM('scan', 'browse', 'upload') NOT NULL DEFAULT 'scan',
    renamed_by INT,
    renamed_at DATETIME,
    -- JSON snapshot of the exact metadata fields (Class Name, PH, SEC,
    -- Plot, File Name, Page No/Count, Date, and Land Acquisition fields)
    -- confirmed at rename/validate time — the source of truth Verify reads
    -- from instead of re-parsing the filename.
    rename_meta TEXT,
    PRIMARY KEY (document_id),
    UNIQUE KEY uq_folder_file (folder_id, raw_filename),
    CONSTRAINT fk_documents_folder FOREIGN KEY (folder_id)
        REFERENCES folders(folder_id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_scanned_by FOREIGN KEY (scanned_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_renamed_by FOREIGN KEY (renamed_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Table: validations
-- -----------------------------------------------------
CREATE TABLE validations (
    validation_id INT AUTO_INCREMENT,
    document_id INT,
    validated_by INT,
    decision ENUM('approve', 'change', 'needs_revision'),
    remarks VARCHAR(500),
    validated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (validation_id),
    CONSTRAINT fk_validations_document FOREIGN KEY (document_id)
        REFERENCES documents(document_id) ON DELETE CASCADE,
    CONSTRAINT fk_validations_user FOREIGN KEY (validated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Table: activity_log
-- -----------------------------------------------------
CREATE TABLE activity_log (
    log_id INT AUTO_INCREMENT,
    user_id INT,
    action_type ENUM('create', 'update', 'delete', 'login', 'view', 'download'),
    description VARCHAR(500),
    target_document_id INT,
    target_user_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_activity_target_doc FOREIGN KEY (target_document_id)
        REFERENCES documents(document_id) ON DELETE SET NULL,
    CONSTRAINT fk_activity_target_user FOREIGN KEY (target_user_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;