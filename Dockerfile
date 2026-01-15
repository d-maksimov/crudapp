version: '3.8'

services:
  db:
    build:
      context: .
      dockerfile: mysql.Dockerfile
    container_name: mysql_db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_DATABASE: myapp
      MYSQL_USER: user
      MYSQL_PASSWORD: userpassword
    ports:
      - "3306:3306"
    volumes:
      - db_data:/var/lib/mysql
      - ./init.sql:/docker-entrypoint-initdb.d/init.sql
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p$$MYSQL_ROOT_PASSWORD"]
      timeout: 20s
      retries: 10

  web:
    build:
      context: .
      dockerfile: php.Dockerfile
      # или используйте просто Dockerfile, если он для PHP
      # dockerfile: Dockerfile
    container_name: php_web
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "8080:80"
    volumes:
      # Если нужно монтировать код PHP
      - ./app:/var/www/html
    environment:
      DB_HOST: db
      DB_NAME: myapp
      DB_USER: user
      DB_PASSWORD: userpassword
    networks:
      - app_network

  phpmyadmin:
    image: phpmyadmin/phpmyadmin:5.2.1
    container_name: pma
    restart: unless-stopped
    depends_on:
      - db
    environment:
      PMA_HOST: db
      PMA_PORT: 3306
      UPLOAD_LIMIT: 100M
    ports:
      - "5000:80"
    networks:
      - app_network

volumes:
  db_data:
    driver: local

networks:
  app_network:
    driver: bridge
