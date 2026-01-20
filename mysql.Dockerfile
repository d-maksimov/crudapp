# mysql.Dockerfile
FROM mysql:8.0

ENV MYSQL_ROOT_PASSWORD=rootpassword

COPY init.sql /docker-entrypoint-initdb.d/

# Добавляем конфигурацию для разрешения удаленных подключений
RUN echo "[mysqld]" > /etc/mysql/conf.d/custom.cnf && \
    echo "bind-address = 0.0.0.0" >> /etc/mysql/conf.d/custom.cnf && \
    echo "skip-name-resolve" >> /etc/mysql/conf.d/custom.cnf
