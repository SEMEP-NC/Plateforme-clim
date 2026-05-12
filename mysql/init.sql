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