-- APEX: crea la base de datos y un usuario propio para el sistema (Linux / Windows / macOS).
-- Uso:  mysql -u root -p < deploy/crear_base_mysql.sql
-- ANTES de ejecutarlo cambia 'CAMBIA-ESTA-CLAVE' por una contraseña larga y escríbela también en
-- config/config.local.php (DB_PASS).
CREATE DATABASE IF NOT EXISTS apex_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'apex'@'localhost' IDENTIFIED BY 'CAMBIA-ESTA-CLAVE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES
    ON apex_db.* TO 'apex'@'localhost';
FLUSH PRIVILEGES;
