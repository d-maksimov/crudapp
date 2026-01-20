-- Инициализация базы данных appdb
CREATE DATABASE IF NOT EXISTS appdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE appdb;

-- Таблица пользователей
 CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица тренировок
CREATE TABLE IF NOT EXISTS workouts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    workout_date DATE,
    workout_type VARCHAR(50),
    duration INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Тестовые данные (РАСКОММЕНТИРУЙТЕ И users ИЛИ ЗАКОММЕНТИРУЙТЕ workouts)

-- Вариант A: Раскомментируйте users:
INSERT IGNORE INTO users (username, password) VALUES 
('alex', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('maria', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('john', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm');

INSERT IGNORE INTO workouts (user_id, workout_date, workout_type, duration, notes) VALUES 
(1, '2024-01-20', 'Running', 40, 'Morning 5km run'),
(1, '2024-01-21', 'Cycling', 60, 'Mountain biking'),
(2, '2024-01-20', 'Swimming', 45, 'Pool training'),
(2, '2024-01-22', 'Yoga', 50, 'Morning session'),
(3, '2024-01-23', 'Weightlifting', 75, 'Heavy lifting');

-- Вариант B: Или уберите тестовые данные вообще:
-- INSERT IGNORE INTO workouts (user_id, workout_date, workout_type, duration, notes) VALUES 
-- (1, '2024-01-20', 'Running', 40, 'Morning 5km run'),
-- ...

SELECT 'Database appdb initialized successfully!' as message;
