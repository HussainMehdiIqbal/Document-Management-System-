-- ============================================================
-- Migration: split the old combined "Operator" role into three
-- distinct roles (Scanner / Validator / Renamer).
--
-- Only needed if your `roles` table was seeded from an older
-- version of dha_dms.sql (Admin + Operator only). A fresh install
-- from the current dha_dms.sql already has the correct rows.
--
-- Run this ONCE against your existing database, then confirm the
-- roles table looks like: 1=Admin, 2=Scanner, 3=Validator, 4=Renamer.
-- Existing users with role_id=2 keep their role_id — only the
-- role's name/description changes, so no user re-assignment is
-- needed for that role. Any user who should be a Validator or
-- Renamer instead will need role_id updated to 3 or 4 respectively
-- via the Users page.
-- ============================================================

USE dms_database;

-- Rename the existing "Operator" row (role_id 2) to "Scanner".
UPDATE roles
SET role_name = 'Scanner',
    description = 'Can scan documents and upload them into folders'
WHERE role_name = 'Operator';

-- Add the two roles the app already expects (role_id 3 and 4)
-- if they don't exist yet. Uses INSERT ... SELECT with a NOT EXISTS
-- guard so it's safe to re-run.
INSERT INTO roles (role_name, description)
SELECT 'Validator', 'Can verify/validate scanned documents — approve, flag as changed, or send back'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Validator');

INSERT INTO roles (role_name, description)
SELECT 'Renamer', 'Can rename and re-file scanned documents'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Renamer');

-- Verify afterward — role_id must read 1=Admin, 2=Scanner,
-- 3=Validator, 4=Renamer for the app's nav/permission checks
-- (which key off these exact IDs) to work correctly.
SELECT role_id, role_name FROM roles ORDER BY role_id;
