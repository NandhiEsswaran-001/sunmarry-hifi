-- Database: u757552137_matrimony_db
USE u757552137_matrimony_db;

-- Users table with role-based access
-- Matching the existing schema from hosting
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    role ENUM('super_admin', 'manager', 'support', 'admin', 'staff', 'viewer') NOT NULL DEFAULT 'support',
    profiles_viewed INT DEFAULT 0,
    last_login DATETIME DEFAULT NULL,
    credits INT DEFAULT 10,
    phone VARCHAR(20) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
);

-- Insert default admin
-- Password: admin (use the existing hash from your hosting or hash for 'admin123')
INSERT INTO users (id, username, password, role, credits, profiles_viewed) VALUES 
(1, 'admin', '$2y$10$bFtZ8krRD785m8SOJ9oIZeHSjZnU5jyOJrNgxq49V6uxEyLh1eacO', 'super_admin', 10, 0)
ON DUPLICATE KEY UPDATE username = username;

-- Insert sample staff/users
INSERT INTO users (username, password, role, credits, profiles_viewed, phone) VALUES 
('admin1', '$2y$10$2aINJTiaFyZ9m63nybv5ouvTLJWHE.Z98rao7pbteLO/Ry8Zyqmhu', 'super_admin', 10, 0, NULL),
('staff1', '$2y$10$SEMDDPZAPQZlsOWUk4XB8u3H5734f8G0sqe2.d.EZ/ECKGgGL6lp6', 'manager', 20, 0, '9677317513'),
('user1', '$2y$10$4598CdxC/jV0kwZfoo.1XOMrn386X4Aqj6nhnQpkt6DXiqad6Qsju', 'support', 12, 8, '9677314125');

-- Profiles table
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    district VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    birth_place VARCHAR(150) DEFAULT NULL,
    caste VARCHAR(100) NOT NULL,
    subcaste VARCHAR(255) DEFAULT NULL,
    marriage_type ENUM('First', 'Second', 'முதல்மணம்', 'மறுமணம்') NOT NULL,
    education_type VARCHAR(100) DEFAULT NULL,
    nakshatram VARCHAR(100) DEFAULT NULL,
    religion VARCHAR(50) DEFAULT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    file_upload VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    father_name VARCHAR(255) DEFAULT NULL,
    mother_name VARCHAR(255) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    birth_time VARCHAR(10) DEFAULT NULL,
    kulam VARCHAR(150) DEFAULT NULL,
    rasi VARCHAR(100) DEFAULT NULL,
    brothers_total INT DEFAULT 0,
    brothers_married INT DEFAULT 0,
    sisters_total INT DEFAULT 0,
    sisters_married INT DEFAULT 0,
    profession VARCHAR(150) DEFAULT NULL,
    phone_primary VARCHAR(20) DEFAULT NULL,
    phone_secondary VARCHAR(20) DEFAULT NULL,
    phone_tertiary VARCHAR(20) DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    dosham VARCHAR(255) DEFAULT '',
    education_details VARCHAR(500) DEFAULT '',
    notes TEXT DEFAULT '',
    INDEX idx_gender_marriage (gender, marriage_type),
    INDEX idx_district (district),
    INDEX idx_caste (caste),
    INDEX idx_deleted (deleted_at)
);

-- Insert sample profiles
INSERT INTO profiles (id, name, age, gender, district, city, birth_place, caste, subcaste, marriage_type, education_type, nakshatram, religion, profile_photo, file_upload, created_at, father_name, mother_name, birth_date, birth_time, kulam, rasi, brothers_total, brothers_married, sisters_total, sisters_married, profession, phone_primary, phone_secondary, phone_tertiary, deleted_at, dosham, education_details, notes) VALUES
(1, 'நித்யஸ்ரீ', 23, 'Female', 'Cuddalore', 'உடுமலை', 'உடுமலை', 'நாயுடு (பலிஜா நாயுடு)', NULL, 'First', 'இளங்கலை (UG)', 'மகம்', '', 'uploads/69240a163bfa0_690a3cd188a94_1.jpg', 'uploads/69240a163c3e2_691a966fa9f0c_e247200578db63d8.jpg', '2025-11-24 07:32:38', NULL, NULL, '2002-04-01', '08:06 AM', 'கரணம்', 'மீனம்', 0, 0, 1, 0, 'கணினி வேலை', '9677317512', '9677317516', '', NULL, 'சுத்த ஜாதகம்', 'கம்ப்யூட்டர்', ''),
(2, 'வேல்முருகன் ', 33, 'Female', 'Coimbatore', '', 'உடுமலைப்பேட்டை', '24 மனை தெலுங்கு (8 வீடு)', NULL, 'First', '10 ஆம் வகுப்பு, 12 ஆம் வகுப்பு, ஐ.டி.ஐ, டிப்ளமோ', 'பூசம்', '', 'uploads/69beb45c81812_photo.jpg', 'uploads/69beb498f1e6d_408efc0aaba06db7.jpg', '2026-03-21 15:08:12', NULL, NULL, '1992-11-20', '06:11 AM', 'சொப்பியவர்', 'விருச்சிகம்', 2, 1, 3, 1, 'லேப் டெச்னிசியன்', '', '', '', NULL, 'ராகு கேது', 'டிப்ளமோ கம்ப்யூட்டர்', ''),
(3, 'பாலமுருகன் ', 40, 'Female', 'Tiruppur', 'உடுமலைப்பேட்டை ', 'உடுமலைப்பேட்டை', '24 மனை தெலுங்கு (16 வீடு)', NULL, 'First', 'இளங்கலை (UG)', 'மகம்', '', 'uploads/69beb8223070f_photo.jpg', 'uploads/69beb822309f7_horoscopet.jpg', '2026-03-21 15:24:18', NULL, NULL, '1985-06-08', '07:09 PM', 'ரஜபைரவர்', 'கடகம்', 1, 1, 2, 1, 'சாப்ட்வேர் என்ஜினீயர்', '', '', '', NULL, 'பரிகார செவ்வாய்', 'BE கம்ப்யூட்டர் சயின்ஸ்', '20 தோப்பு ஏக்கர்'),
(4, 'பானு பேகம்', 25, 'Female', 'Coimbatore', 'உக்கடம்', 'ஊத்துக்குளி', 'விஸ்வகர்மா (மலையாளம்)', NULL, 'First', 'முதுகலை (PG)', 'பூரம்', '', 'uploads/695b602b8fd9a_Screenshot 2025-08-27 210956.png', 'uploads/695b602b9057f_690dfe032b97f_Untitled.jpg', '2026-01-05 06:54:35', NULL, NULL, '2001-01-17', '08:08 AM', 'வாட', 'மகரம்', 1, 1, 1, 1, 'பேங்க் மேனேஜர்', '', '', '', NULL, 'ராகு கேது', 'MBA', ''),
(5, 'ப்ரீத்தி', 26, 'Female', 'Dindigul', '', '', 'நாயுடு (பலிஜா நாயுடு)', NULL, 'First', '', '', '', 'uploads/69c377420819d_69088ae902fdc_Chatbot (1).png', 'uploads/69c37742082c7_690898ea9d506_Annotation 2025-05-16 102752.png', '2026-03-25 05:48:50', NULL, NULL, '2000-02-25', '01:00 AM', '', 'தனுசு', 2, 1, 3, 1, '', '', '', '', NULL, '', '', 'கோயம்புத்தூர்'),
(6, 'சாருமதி', 30, 'Female', '', '', '', '', NULL, 'First', '', '', '', 'uploads/69c3886a5bf94_69c03b7ccbcfc_201487-shwetamohan4.jpg', 'uploads/69c3886a5c15c_69beb822309f7_horoscopet.jpg', '2026-03-25 07:02:02', NULL, NULL, '1995-06-15', NULL, '', '', 0, 0, 0, 0, '', '9677317512', '9677317516', '', NULL, '', '', '');

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

-- Insert sample profile views
INSERT INTO support_profile_views (user_id, profile_id) VALUES
(1, 1), (1, 2), (1, 4), (1, 5),
(3, 1), (3, 2), (3, 4);