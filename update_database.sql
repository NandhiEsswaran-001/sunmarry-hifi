-- Database: u757552137_matrimony_db
USE u757552137_matrimony_db;

-- Users table with role-based access
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff', 'viewer') NOT NULL DEFAULT 'viewer',
    phone VARCHAR(20) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    credits INT DEFAULT 0,
    profiles_viewed INT DEFAULT 0,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
   INDEX idx_username (username),
    INDEX idx_role (role)
);

-- Insert default admin (change password after first login)
-- Username: admin, Password: admin123
INSERT INTO users (username, password, role, credits, profiles_viewed) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0, 0)
ON DUPLICATE KEY UPDATE username = username;

-- Profiles table
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    marriage_type VARCHAR(50) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    district VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    birth_place VARCHAR(150) DEFAULT NULL,
    caste VARCHAR(100) DEFAULT NULL,
    subcaste VARCHAR(255) DEFAULT NULL,
    nakshatram VARCHAR(100) DEFAULT NULL,
    rasi VARCHAR(100) DEFAULT NULL,
    religion VARCHAR(50) DEFAULT NULL,
    kulam VARCHAR(150) DEFAULT NULL,
    education_type VARCHAR(100) DEFAULT NULL,
    education_details TEXT DEFAULT NULL,
    dosham ENUM('Yes', 'No', 'Unknown') DEFAULT 'Unknown',
    brothers_total INT DEFAULT 0,
    brothers_married INT DEFAULT 0,
    sisters_total INT DEFAULT 0,
    sisters_married INT DEFAULT 0,
    father_name VARCHAR(255) DEFAULT NULL,
    mother_name VARCHAR(255) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    birth_time VARCHAR(10) DEFAULT NULL,
    profession VARCHAR(150) DEFAULT NULL,
    phone_primary VARCHAR(20) DEFAULT NULL,
    phone_secondary VARCHAR(20) DEFAULT NULL,
    phone_tertiary VARCHAR(20) DEFAULT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    file_upload VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    INDEX idx_gender_marriage (gender, marriage_type),
    INDEX idx_district (district),
    INDEX idx_caste (caste),
    INDEX idx_deleted (deleted_at)
);

-- Registration requests table
CREATE TABLE IF NOT EXISTS registration_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    alternate_phone VARCHAR(20) DEFAULT NULL,
    marriage_type ENUM('முதல்மணம்', 'மறுமணம்', 'First', 'Second') NOT NULL,
    caste VARCHAR(255) NOT NULL,
    birth_date DATE NOT NULL,
    city VARCHAR(150) NOT NULL,
    education VARCHAR(255) NOT NULL,
    status ENUM('new', 'reviewed') NOT NULL DEFAULT 'new',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_phone (phone)
);

-- Support profile views tracking
CREATE TABLE IF NOT EXISTS support_profile_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    profile_id INT NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    INDEX idx_user_viewed (user_id, viewed_at)
);