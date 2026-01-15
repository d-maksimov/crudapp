FROM mysql:8.0

# Копируем init.sql для инициализации базы
COPY init.sql /docker-entrypoint-initdb.d/

# Устанавливаем переменные окружения
ENV MYSQL_ROOT_PASSWORD=rootpassword
ENV MYSQL_DATABASE=appdb

EXPOSE 3306
