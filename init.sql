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


 -- ВАЖНО: СОЗДАЕМ ПОЛЬЗОВАТЕЛЯ И ДАЕМ ПРАВА
-- Удаляем пользователя если существует (для чистоты)
DROP USER IF EXISTS 'user'@'%';
-- Создаем пользователя с доступом с любого хоста
CREATE USER 'user'@'%' IDENTIFIED BY 'userpassword';
-- Даем все права на базу appdb
GRANT ALL PRIVILEGES ON appdb.* TO 'user'@'%';

-- Также создаем пользователя root с доступом с любого хоста (для отладки)
DROP USER IF EXISTS 'root'@'%';
CREATE USER 'root'@'%' IDENTIFIED BY 'rootpassword';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;

-- Применяем изменения прав
FLUSH PRIVILEGES;




SELECT 'Database appdb initialized successfully!' as message;
