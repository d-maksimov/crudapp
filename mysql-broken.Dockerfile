FROM mysql:8.0
COPY init_broken.sql /docker-entrypoint-initdb.d/
ENV MYSQL_ROOT_PASSWORD=rootpassword
ENV MYSQL_DATABASE=appdb
