-- Create the database
CREATE DATABASE IF NOT EXISTS marriage_profile_db;
USE marriage_profile_db;

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Create profiles table
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    marriage_type VARCHAR(50) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    district VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    caste VARCHAR(100),
    subcaste VARCHAR(255),
    nakshatram VARCHAR(100),
    rasi VARCHAR(100),
    religion VARCHAR(50),
    kulam VARCHAR(150),
    education_type VARCHAR(100),
    brothers_total INT DEFAULT 0,
    brothers_married INT DEFAULT 0,
    sisters_total INT DEFAULT 0,
    sisters_married INT DEFAULT 0,
    father_name VARCHAR(255),
    mother_name VARCHAR(255),
    birth_date DATE,
    birth_time VARCHAR(10),
    profession VARCHAR(150),
    phone_primary VARCHAR(20),
    phone_secondary VARCHAR(20),
    phone_tertiary VARCHAR(20),
    profile_photo VARCHAR(255),
    file_upload VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS registration_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    alternate_phone VARCHAR(20) DEFAULT NULL,
    marriage_type ENUM('First', 'Second') NOT NULL,
    caste VARCHAR(255) NOT NULL,
    birth_date DATE NOT NULL,
    city VARCHAR(150) NOT NULL,
    education VARCHAR(255) NOT NULL,
    status ENUM('new', 'reviewed') NOT NULL DEFAULT 'new',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user
-- Default credentials: username: admin, password: admin123
INSERT INTO users (username, password) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
