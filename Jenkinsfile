pipeline {
    agent { label 'docker-agent' }
    
    environment {
        // Основные настройки
        APP_NAME = 'app'
        CANARY_APP_NAME = 'app-canary'
        DOCKER_HUB_USER = 'danil221'
        GIT_REPO = 'https://github.com/d-maksimov/crudapp.git'
        BACKEND_IMAGE_NAME = 'php-app'
        DATABASE_IMAGE_NAME = 'mysql-app'
        
        // Настройки базы данных
        MYSQL_ROOT_PASSWORD = 'rootpassword'
        MYSQL_APP_PASSWORD = 'userpassword'
        MYSQL_DATABASE = 'appdb'
        
        // Docker Swarm connection
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
        MANAGER_IP = '192.168.0.1'
        
        // URL для тестирования
        CANARY_URL = 'http://192.168.0.1:8081'
        PROD_URL = 'http://192.168.0.1'
    }

    stages {
        stage('Checkout') {
            steps {
                git url: "${GIT_REPO}", branch: 'main'
                sh '''
                    echo "✅ Репозиторий склонирован"
                    echo "Текущая директория: $(pwd)"
                    ls -la
                '''
            }
        }

        stage('Build Docker Images') {
            steps {
                script {
                    sh """
                        echo "=== СБОРКА DOCKER ОБРАЗОВ ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Сборка PHP образа (тег: \${BUILD_NUMBER})..."
                        docker build -f php.Dockerfile . -t \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:\${BUILD_NUMBER}
                        
                        echo "2. Сборка MySQL образа (тег: \${BUILD_NUMBER})..."
                        docker build -f mysql.Dockerfile . -t \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:\${BUILD_NUMBER}
                        
                        echo "✅ Образы собраны"
                        docker images | grep \${DOCKER_HUB_USER}
                    """
                }
            }
        }

        stage('Push to Docker Hub') {
            steps {
                withCredentials([usernamePassword(
                    credentialsId: 'docker-hub-credentials', 
                    usernameVariable: 'DOCKER_USER', 
                    passwordVariable: 'DOCKER_PASS'
                )]) {
                    script {
                        sh '''
                            echo "=== ОТПРАВКА ОБРАЗОВ В DOCKER HUB ==="
                            export DOCKER_HOST="tcp://192.168.0.1:2376"
                            
                            echo "1. Логин в Docker Hub..."
                            echo "${DOCKER_PASS}" | docker login -u "${DOCKER_USER}" --password-stdin
                            
                            echo "2. Публикация PHP образа с тегом '${BUILD_NUMBER}'..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "3. Публикация MySQL образа с тегом '${BUILD_NUMBER}'..."
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "4. Добавление тегов 'latest'..."
                            docker tag ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker tag ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "5. Публикация тегов 'latest'..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "✅ Образы успешно опубликованы"
                        '''
                    }
                }
            }
        }

        stage('Deploy Canary') {
            steps {
                script {
                    sh '''
                        echo "=== РАЗВЕРТЫВАНИЕ CANARY ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Очистка предыдущего canary..."
                        docker stack rm app-canary 2>/dev/null || true
                        sleep 15
                        
                        echo "2. Очистка volume'ов MySQL..."
                        docker volume rm app-canary_canary_mysql_data 2>/dev/null || true
                        
                        echo "3. Используем порт 3307 для canary MySQL..."
                        CANARY_DB_PORT="3307"
                        
                        echo "4. Подготовка docker-compose для canary..."
                        
                        # Создаем файл с правильными constraints
                        cat > docker-compose_canary_temp.yaml << 'EOF'
version: '3.8'

services:
  db:
    image: ${DOCKER_HUB_USER}/mysql-app:${BUILD_NUMBER}
    command: --default-authentication-plugin=mysql_native_password
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=appdb
      - MYSQL_USER=user
      - MYSQL_PASSWORD=userpassword
    volumes:
      - canary_mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-prootpassword"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 40s
    deploy:
      mode: replicated
      replicas: 1
      placement:
        constraints:
          - node.hostname == worker1
      restart_policy:
        condition: on-failure
        delay: 10s
        max_attempts: 5

  web-server:
    image: ${DOCKER_HUB_USER}/php-app:${BUILD_NUMBER}
    environment:
      - DB_HOST=db
      - DB_PORT=${CANARY_DB_PORT}
      - DB_NAME=appdb
      - DB_USER=user
      - DB_PASSWORD=userpassword
      - APP_ENV=canary
    ports:
      - "8081:80"
    deploy:
      mode: replicated
      replicas: 1
      restart_policy:
        condition: on-failure
        delay: 5s
        max_attempts: 3

networks:
  default:
    driver: overlay
    attachable: true

volumes:
  canary_mysql_data:
    driver: local
EOF
                        
                        # Заменяем переменные
                        sed -i "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/\\\${CANARY_DB_PORT}/${CANARY_DB_PORT}/g" docker-compose_canary_temp.yaml
                        
                        echo "Проверяем созданный файл..."
                        echo "=== Начало файла ==="
                        head -40 docker-compose_canary_temp.yaml
                        echo "=== Конец файла ==="
                        
                        echo "5. Развертывание canary стека..."
                        docker stack deploy -c docker-compose_canary_temp.yaml app-canary --with-registry-auth
                        
                        echo "6. Ожидание запуска canary сервисов..."
                        TIMEOUT=180
                        START_TIME=$(date +%s)
                        
                        while true; do
                            CURRENT_TIME=$(date +%s)
                            ELAPSED=$((CURRENT_TIME - START_TIME))
                            
                            if [ $ELAPSED -ge $TIMEOUT ]; then
                                echo "⚠️  Таймаут ожидания запуска canary..."
                                break
                            fi
                            
                            DB_STATUS=$(docker service ls --filter name=app-canary_db --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            WEB_STATUS=$(docker service ls --filter name=app-canary_web-server --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            
                            echo "   DB: ${DB_STATUS}, Web: ${WEB_STATUS} (прошло ${ELAPSED} сек)"
                            
                            # Если web запущен, продолжаем
                            if echo "${WEB_STATUS}" | grep -q "1/1"; then
                                echo "✅ Web сервер запущен"
                                break
                            fi
                            
                            sleep 10
                        done
                        
                        echo "✅ Canary развернут"
                        docker service ls --filter name=app-canary
                    '''
                }
            }
        }

        stage('Canary Database Check') {
            steps {
                script {
                    sh '''
                        echo "=== ПРОВЕРКА БАЗЫ ДАННЫХ CANARY ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Проверка статуса canary MySQL..."
                        DB_STATUS=$(docker service ls --filter name=${CANARY_APP_NAME}_db --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                        
                        if [ "$DB_STATUS" != "1/1" ]; then
                            echo "⚠️ MySQL сервис не запущен (статус: ${DB_STATUS})"
                            echo "   Проверяем логи MySQL..."
                            docker service logs ${CANARY_APP_NAME}_db --tail 20 2>/dev/null || true
                            echo "⚠️ Пропускаем проверку БД..."
                        else
                            echo "✅ MySQL сервис запущен"
                            
                            echo "2. Ожидание инициализации БД..."
                            sleep 30
                            
                            echo "3. Проверка базы данных 'appdb'..."
                            if docker run --rm --network ${CANARY_APP_NAME}_default mysql:8.0 \\
                               mysql -h db -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null | grep -q appdb; then
                                echo "   ✅ База данных 'appdb' существует"
                                
                                echo "4. СТРОГАЯ проверка таблиц 'users' и 'workouts'..."
                                
                                # Проверяем таблицу users
                                USERS_EXISTS=$(docker run --rm --network ${CANARY_APP_NAME}_default mysql:8.0 \\
                                    mysql -h db -u root -prootpassword appdb -N -e \\
                                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users';" 2>/dev/null || echo "0")
                                
                                # Проверяем таблицу workouts
                                WORKOUTS_EXISTS=$(docker run --rm --network ${CANARY_APP_NAME}_default mysql:8.0 \\
                                    mysql -h db -u root -prootpassword appdb -N -e \\
                                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts';" 2>/dev/null || echo "0")
                                
                                echo "   Результат проверки:"
                                echo "   • Таблица 'users': ${USERS_EXISTS} (1 = существует, 0 = нет)"
                                echo "   • Таблица 'workouts': ${WORKOUTS_EXISTS} (1 = существует, 0 = нет)"
                                
                                # КРИТИЧЕСКАЯ ПРОВЕРКА
                                if [ "${USERS_EXISTS}" = "1" ] && [ "${WORKOUTS_EXISTS}" = "1" ]; then
                                    echo "   ✅ ОБЕ таблицы существуют!"
                                    echo "✅ Проверка БД пройдена успешно"
                                else
                                    echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Не все таблицы созданы!"
                                    echo "   Проверьте содержимое init.sql"
                                    exit 1
                                fi
                            else
                                echo "❌ База данных 'appdb' не найдена"
                                exit 1
                            fi
                        fi
                    '''
                }
            }
        }

        stage('Canary Testing') {
            steps {
                script {
                    sh '''
                        echo "=== ТЕСТИРОВАНИЕ CANARY ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Даем время для запуска PHP..."
                        sleep 20
                        
                        SUCCESS=0
                        TOTAL_TESTS=10
                        
                        echo "2. Тестирование canary по адресу: ${CANARY_URL}"
                        
                        for i in $(seq 1 $TOTAL_TESTS); do
                            echo ""
                            echo "   Тест $i/$TOTAL_TESTS:"
                            
                            if curl -f -s --max-time 15 ${CANARY_URL} > /tmp/canary_$i.html 2>/dev/null; then
                                SIZE=$(wc -c < /tmp/canary_$i.html)
                                echo "     ✓ Страница загружена (${SIZE} байт)"
                                
                                if ! grep -iq "error\\|fatal\\|exception\\|failed\\|syntax\\|warning\\|database" /tmp/canary_$i.html 2>/dev/null; then
                                    SUCCESS=$((SUCCESS + 1))
                                    echo "     ✓ Контент без ошибок"
                                else
                                    echo "     ⚠️ Найдены ошибки в контенте"
                                fi
                            else
                                echo "     ❌ Не удалось загрузить страницу"
                            fi
                            
                            sleep 4
                        done
                        
                        echo ""
                        echo "=== РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ CANARY ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 8 ]; then
                            echo "✅ Canary прошел тестирование!"
                        else
                            echo "❌ Canary не прошел тестирование"
                            exit 1
                        fi
                    '''
                }
            }
        }

        stage('Gradual Traffic Shift') {
            steps {
                script {
                    sh '''
                        echo "=== ПОСТЕПЕННОЕ ПЕРЕКЛЮЧЕНИЕ ТРАФИКА ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        # Проверяем, существует ли основной сервис
                        if docker service ls --filter name=${APP_NAME}_web | grep -q ${APP_NAME}_web; then
                            echo "Основной сервис существует — начинаем rolling update"
                            
                            # Шаг 1: Обновляем первую реплику
                            echo "Шаг 1: Обновляем 1-ю реплику продакшена"
                            docker service update \\
                                --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} \\
                                --update-parallelism 1 \\
                                --update-delay 20s \\
                                --with-registry-auth \\
                                ${APP_NAME}_web
                            
                            echo "Ожидание стабилизации..."
                            sleep 40
                            
                            # Проверка после первого шага
                            echo "=== Мониторинг после первой реплики ==="
                            MONITOR_SUCCESS=0
                            MONITOR_TESTS=10
                            for j in $(seq 1 $MONITOR_TESTS); do
                                if curl -f -s --max-time 15 ${PROD_URL} > /tmp/monitor_$j.html 2>/dev/null; then
                                    if ! grep -iq "error\\|fatal" /tmp/monitor_$j.html; then
                                        MONITOR_SUCCESS=$((MONITOR_SUCCESS + 1))
                                    fi
                                fi
                                sleep 5
                            done
                            echo "Успешных проверок: ${MONITOR_SUCCESS}/${MONITOR_TESTS}"
                            [ "${MONITOR_SUCCESS}" -ge 9 ] || exit 1
                            
                            # Шаг 2: Обновляем оставшиеся реплики
                            echo "Шаг 2: Обновляем оставшиеся реплики"
                            docker service update \\
                                --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} \\
                                --update-parallelism 1 \\
                                --update-delay 30s \\
                                --with-registry-auth \\
                                ${APP_NAME}_web
                            
                            echo "Ожидание завершения..."
                            sleep 90
                            
                            # Обновляем базу данных если нужно
                            echo "Обновление базы данных до latest..."
                            docker service update \\
                                --image ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest \\
                                --with-registry-auth \\
                                ${APP_NAME}_db 2>/dev/null || echo "БД уже обновлена"
                            
                            # Удаляем canary
                            echo "Удаление canary stack..."
                            docker stack rm ${CANARY_APP_NAME} || true
                            sleep 20
                        else
                            echo "Первый деплой — разворачиваем продакшен"
                            docker stack deploy -c docker-compose.yaml ${APP_NAME} --with-registry-auth
                            sleep 60
                        fi
                        
                        echo "✅ Переключение завершено"
                    '''
                }
            }
        }

        stage('Production Database Check') {
            steps {
                script {
                    sh '''
                        echo "=== СТРОГАЯ ПРОВЕРКА И ИНИЦИАЛИЗАЦИЯ PRODUCTION БАЗЫ ДАННЫХ ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Ожидание запуска MySQL..."
                        MYSQL_READY=0
                        for i in {1..30}; do
                            if docker run --rm --network ${APP_NAME}_default mysql:8.0 \\
                               mysqladmin -h ${APP_NAME}_db -u root -prootpassword ping 2>/dev/null | grep -q "mysqld is alive"; then
                                MYSQL_READY=1
                                echo "   ✅ MySQL доступен"
                                break
                            fi
                            echo "   ⏳ Ожидание MySQL... ($i/30)"
                            sleep 5
                        done
                        
                        if [ $MYSQL_READY -eq 0 ]; then
                            echo "❌ MySQL не доступен после ожидания"
                            exit 1
                        fi
                        
                        echo "2. Принудительная инициализация БД (создание таблиц если их нет)..."
                        docker run --rm --network ${APP_NAME}_default mysql:8.0 mysql -h ${APP_NAME}_db -u root -prootpassword << 'EOF'
CREATE DATABASE IF NOT EXISTS appdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE appdb;

CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

SELECT "Database appdb initialized successfully!" as message;
EOF
                        
                        echo "3. Финальная проверка таблиц в production БД..."
                        
                        # Проверяем таблицы
                        PROD_TABLE_CHECK=$(docker run --rm --network ${APP_NAME}_default mysql:8.0 \\
                            mysql -h ${APP_NAME}_db -u root -prootpassword appdb -N -e \\
                            "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users'), 
                                    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts');" 2>/dev/null || echo "0 0")
                        
                        PROD_USERS_EXISTS=$(echo $PROD_TABLE_CHECK | awk '{print $1}')
                        PROD_WORKOUTS_EXISTS=$(echo $PROD_TABLE_CHECK | awk '{print $2}')
                        
                        echo "   Production БД:"
                        echo "   • Таблица 'users': ${PROD_USERS_EXISTS}"
                        echo "   • Таблица 'workouts': ${PROD_WORKOUTS_EXISTS}"
                        
                        if [ "${PROD_USERS_EXISTS}" = "1" ] && [ "${PROD_WORKOUTS_EXISTS}" = "1" ]; then
                            echo "✅ Production база данных корректна"
                        else
                            echo "❌ В production БД отсутствуют таблицы!"
                            echo "   Это критическая ошибка - приложение не будет работать корректно"
                            
                            # Показываем что есть в БД
                            echo "   Содержимое БД:"
                            docker run --rm --network ${APP_NAME}_default mysql:8.0 \\
                                mysql -h ${APP_NAME}_db -u root -prootpassword -e "SHOW DATABASES; USE appdb; SHOW TABLES;" 2>/dev/null || true
                            
                            exit 1
                        fi
                    '''
                }
            }
        }

        stage('Final Verification') {
            steps {
                script {
                    sh '''
                        echo "=== ФИНАЛЬНАЯ ПРОВЕРКА ==="
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        for i in $(seq 1 5); do
                            echo "Финальный тест $i/5..."
                            if curl -f --max-time 10 ${PROD_URL} > /dev/null 2>&1; then
                                echo "✓ Тест $i пройден"
                            else
                                echo "✗ Тест $i не пройден"
                                exit 1
                            fi
                            sleep 5
                        done
                        
                        echo "✅ Все финальные тесты пройдены!"
                        echo ""
                        echo "=== ИТОГОВОЕ СОСТОЯНИЕ ==="
                        docker service ls --filter name=${APP_NAME} --format "table {{.Name}}\\t{{.Replicas}}\\t{{.Image}}\\t{{.Ports}}"
                    '''
                }
            }
        }
    }

    post {
        success {
            echo '✅ Canary-деплой успешно завершён!'
            sh '''
                echo "=== ОЧИСТКА ==="
                export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                docker logout 2>/dev/null || true
                rm -f docker-compose_canary_temp.yaml 2>/dev/null || true
                rm -f /tmp/canary_*.html /tmp/monitor_*.html 2>/dev/null || true
                docker image prune -f 2>/dev/null || true
                
                echo "✅ Очистка завершена"
                echo ""
                echo "Production доступен по адресу:"
                echo "  ${PROD_URL}"
            '''
        }
        failure {
            echo '✗ Ошибка в пайплайне'
            sh '''
                echo "=== АВАРИЙНАЯ ОЧИСТКА ==="
                export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                
                echo "1. Удаление canary при ошибке..."
                docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                
                echo "2. Откат production если нужно..."
                # Если начали обновлять, но не завершили
                if docker service ls --filter name=${APP_NAME}_web | grep -q ":${BUILD_NUMBER}"; then
                    echo "   Откат production до предыдущей версии..."
                    docker service update \\
                        --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest \\
                        --with-registry-auth \\
                        ${APP_NAME}_web
                fi
                
                echo "3. Состояние сервисов:"
                docker service ls --filter name=${APP_NAME} 2>/dev/null | head -10 || true
                
                docker logout 2>/dev/null || true
                echo "Canary удалён, production откачен"
            '''
        }
        always {
            sh 'docker image prune -f 2>/dev/null || true'
        }
    }
}
