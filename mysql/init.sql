CREATE TABLE IF NOT EXISTS equipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip VARCHAR(50) NOT NULL,
    port INT NOT NULL,
    slave_id INT NOT NULL,
    localisation VARCHAR(75) NOT NULL DEFAULT '',
    power INT,
    UI INT NOT NULL,
    enabled TINYINT DEFAULT 1,
    return_temp DECIMAL(5,1) NULL,
    outside_temp DECIMAL(5,1) NULL,
    setpoint DECIMAL(5,1) NULL,
    state TINYINT(1) DEFAULT 0,
    fault TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_UI_ip_port_slave (UI, ip, port, slave_id)
);

CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NULL,
    group_id INT NULL,
    action VARCHAR(20) NULL,
    temperature INT NULL,
    execution_time DATETIME NOT NULL,
    repeat_days VARCHAR(32) NULL,
    executed TINYINT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

);

CREATE TABLE IF NOT EXISTS command_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT,
    schedule_id INT,
    action VARCHAR(50),
    status VARCHAR(50),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS discovered_units (
    device_id VARCHAR(100) PRIMARY KEY,
    port INT,
    slave_id INT,
    ip VARCHAR(50),
    name VARCHAR(100),
    model INT,
    last_seen DATETIME,
    online TINYINT DEFAULT 1
);

CREATE TABLE IF NOT EXISTS discovery_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    start_ip VARCHAR(64) NOT NULL,
    end_ip VARCHAR(64) NOT NULL,
    ports VARCHAR(255) NOT NULL,
    slave_ids VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups_hvac (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE equipment_groups (
    equipment_id INT NOT NULL,
    group_id INT NOT NULL,
    PRIMARY KEY (equipment_id, group_id),

    FOREIGN KEY (equipment_id)
        REFERENCES equipments(id)
        ON DELETE CASCADE,

    FOREIGN KEY (group_id)
        REFERENCES groups_hvac(id)
        ON DELETE CASCADE
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user','viewer') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO discovery_config(start_ip, end_ip, ports, slave_ids)
SELECT '10.5.0.20', '10.5.0.20', '502', '1'
WHERE NOT EXISTS (
    SELECT 1 FROM discovery_config
);

CREATE TABLE IF NOT EXISTS equipment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    setpoint DECIMAL(5,1) NULL,
    return_temp DECIMAL(5,1) NULL,
    outside_temp DECIMAL(5,1) NULL,
    state TINYINT NULL, -- 1 = ON, 0 = OFF
    fault TINYINT DEFAULT 0,

    INDEX (equipment_id),
    INDEX (created_at)
);

CREATE TABLE equipment_fault_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    fault_code INT NOT NULL,
    fault_name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mail_sent TINYINT(1) NOT NULL DEFAULT 0,
    mail_sent_at DATETIME NULL,

    INDEX(equipment_id),
    INDEX(created_at)
);

CREATE TABLE mail_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT DEFAULT 587,
    smtp_user VARCHAR(255),
    smtp_password VARCHAR(255),
    smtp_secure VARCHAR(20) DEFAULT 'tls',
    sender_name VARCHAR(100),
    sender_email VARCHAR(255),
    enabled TINYINT DEFAULT 1
);
INSERT INTO mail_accounts (
    id,
    smtp_host,
    smtp_port,
    smtp_user,
    smtp_password,
    smtp_secure,
    sender_name,
    sender_email,
    enabled
)
VALUES (1,'',587,'','','tls','','',1);


CREATE TABLE mail_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(255) NOT NULL,
    enabled TINYINT DEFAULT 1
);


CREATE TABLE mail_config (
    id INT PRIMARY KEY DEFAULT 1,
    enable_alarm TINYINT DEFAULT 1,
    enable_return TINYINT DEFAULT 1,
    delay_seconds INT DEFAULT 60
);
INSERT INTO mail_config(id,enable_alarm,enable_return,delay_seconds) VALUES(1,1,1,60);

CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    user_id INT NULL,
    username VARCHAR(100) NULL,

    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id INT NULL,

    description TEXT NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,

    INDEX(created_at),
    INDEX(user_id),
    INDEX(action)
);

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) DEFAULT 'Autre',
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE mail_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed TINYINT DEFAULT 0
);