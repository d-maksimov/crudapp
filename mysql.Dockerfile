FROM mysql:8.0

# Копируем init.sql
COPY init.sql /docker-entrypoint-initdb.d/

# Убедимся, что файл имеет правильные права
RUN chmod 644 /docker-entrypoint-initdb.d/init.sql

# Проверяем, что файл скопирован
RUN ls -la /docker-entrypoint-initdb.d/

# Конфигурация MySQL
RUN echo "[mysqld]" > /etc/mysql/conf.d/custom.cnf && \
    echo "bind-address = 0.0.0.0" >> /etc/mysql/conf.d/custom.cnf && \
    echo "skip-name-resolve" >> /etc/mysql/conf.d/custom.cnf
