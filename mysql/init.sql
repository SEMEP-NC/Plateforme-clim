CREATE TABLE equipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip VARCHAR(50) NOT NULL,
    slave_id INT NOT NULL,
    enabled TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    action VARCHAR(20) NOT NULL,
    temperature INT,
    execution_time DATETIME NOT NULL,
    executed TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (equipment_id)
    REFERENCES equipments(id)
    ON DELETE CASCADE
);

CREATE TABLE command_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT,
    action VARCHAR(50),
    status VARCHAR(50),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE discovered_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100),
    mac VARCHAR(50),
    ip VARCHAR(50),
    name VARCHAR(100),
    model VARCHAR(100),
    last_seen DATETIME,
    online TINYINT DEFAULT 1
);