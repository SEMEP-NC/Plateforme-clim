CREATE TABLE IF NOT EXISTS equipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip VARCHAR(50) NOT NULL,
    port INT NOT NULL,
    slave_id INT NOT NULL,
    power INT,
    UI INT NOT NULL,
    enabled TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_UI_ip_port_slave (UI, ip, port, slave_id)
);

CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    action VARCHAR(20) NULL,
    temperature INT NULL,
    execution_time DATETIME NOT NULL,
    repeat_days VARCHAR(32) NULL,
    executed TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (equipment_id)
    REFERENCES equipments(id)
    ON DELETE CASCADE
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

INSERT INTO discovery_config(start_ip, end_ip, ports, slave_ids)
SELECT '10.5.0.20', '10.5.0.20', '502', '1'
WHERE NOT EXISTS (
    SELECT 1 FROM discovery_config
);