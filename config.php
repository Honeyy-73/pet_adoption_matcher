<?php
// ---- Database connection (XAMPP defaults) ----
const DB_HOST = '127.0.0.1';
const DB_NAME = 'pet_adoption_matcher';
const DB_USER = 'root';
const DB_PASS = '';

// Key for AES_ENCRYPT / AES_DECRYPT on adopters.contact_no.
// Must match the key used by the seed data in database.sql.
const ENC_KEY = 'PAM_SecretKey_2026';

// Folder that holds mysqldump.exe and mysql.exe (change if XAMPP is not in C:\xampp)
const MYSQL_BIN = 'C:/xampp/mysql/bin/';
