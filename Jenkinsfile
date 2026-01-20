pipeline {
    agent {
        label 'docker-agent'
    }
    
    environment {
        APP_NAME = 'app'
        CANARY_APP_NAME = 'app-canary'
        DOCKER_HUB_USER = 'danil221'
        GIT_REPO = 'https://github.com/d-maksimov/crudapp.git'
        BACKEND_IMAGE_NAME = 'php-app'
        DATABASE_IMAGE_NAME = 'mysql-app'
        MANAGER_IP = '192.168.0.1'
        MYSQL_ROOT_PASSWORD = 'rootpassword'
        MYSQL_APP_PASSWORD = 'userpassword'
        MYSQL_DATABASE = 'appdb'
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
    }
    
    stages {
        stage('Checkout') {
            steps {
                git branch: 'main', url: "${GIT_REPO}"
                sh 'echo "✅ Репозиторий склонирован"'
            }
        }
        
        stage('Build Docker Images') {
            steps {
                script {
                    sh """
                        echo "=== Сборка Docker образов ==="
                        
                        echo "1. Сборка PHP образа..."
                        docker build -f php.Dockerfile . -t ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                        
                        echo "2. Сборка MySQL образа..."
                        docker build -f mysql.Dockerfile . -t ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                        
                        echo "✅ Образы собраны"
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
                        sh """
                            echo "=== Отправка образов в Docker Hub ==="
                            
                            echo "\${DOCKER_PASS}" | docker login -u "\${DOCKER_USER}" --password-stdin
                            
                            echo "1. Публикация PHP образа с тегом ${BUILD_NUMBER}..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "2. Публикация MySQL образа с тегом ${BUILD_NUMBER}..."
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "3. Добавление тега latest..."
                            docker tag ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker tag ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "4. Публикация тегов latest..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "✅ Образы успешно опубликованы"
                        """
                    }
                }
            }
        }
        
        stage('Deploy Canary') {
            steps {
                script {
                    sh """
                        echo "=== Развёртывание Canary ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        echo "1. Очистка предыдущего canary..."
                        docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                        sleep 15
                        
                        echo "2. Подготовка docker-compose для canary..."
                        cp docker-compose_canary.yaml docker-compose_canary_temp.yaml
                        sed -i "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        
                        echo "3. Проверка конфигурации:"
                        echo "---"
                        grep "image:" docker-compose_canary_temp.yaml
                        echo "---"
                        
                        echo "4. Развертывание canary стека..."
                        docker stack deploy -c docker-compose_canary_temp.yaml ${CANARY_APP_NAME} --with-registry-auth
                        
                        echo "5. Ожидание запуска canary сервисов..."
                        TIMEOUT=300  # 5 минут для MySQL
                        START_TIME=\$(date +%s)
                        
                        while true; do
                            CURRENT_TIME=\$(date +%s)
                            ELAPSED=\$((CURRENT_TIME - START_TIME))
                            
                            if [ \$ELAPSED -ge \$TIMEOUT ]; then
                                echo "❌ Таймаут ожидания запуска canary"
                                echo "Статус сервисов:"
                                docker service ls --filter name=${CANARY_APP_NAME}
                                echo "Логи БД:"
                                docker service logs ${CANARY_APP_NAME}_db --tail 20 2>/dev/null || true
                                exit 1
                            fi
                            
                            DB_STATUS=\$(docker service ls --filter name=${CANARY_APP_NAME}_db --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            WEB_STATUS=\$(docker service ls --filter name=${CANARY_APP_NAME}_web-server --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            
                            echo "  DB: \${DB_STATUS}, Web: \${WEB_STATUS}"
                            
                            if echo "\${DB_STATUS}" | grep -q "1/1" && echo "\${WEB_STATUS}" | grep -q "1/1"; then
                                echo "✅ Canary сервисы запущены"
                                break
                            fi
                            
                            sleep 10
                        done
                        
                        echo "6. Ожидание полной инициализации БД..."
                        sleep 60  # Даем время на выполнение init.sql
                        
                        echo "✅ Canary развернут на порту 8081"
                    """
                }
            }
        }
        
        stage('Check Database Structure') {
    steps {
        script {
            sh '''
                echo "=== ПРОСТАЯ ПРОВЕРКА БАЗЫ ДАННЫХ ==="
                
                export DOCKER_HOST="tcp://192.168.0.1:2376"
                
                echo "1. Проверяем, что сервис БД запущен..."
                docker service ls --filter name=app-canary_db
                
                echo "2. Проверяем наличие сети..."
                docker network ls | grep app-canary_default
                
                echo "3. Проверяем, что контейнер БД существует на ВСЕХ узлах..."
                echo "Поиск по имени сервиса (может быть на worker узле)..."
                
                # Ищем контейнеры по части имени (app-canary_db)
                DB_CONTAINER_ID=$(docker ps --format "{{.ID}}\t{{.Names}}" | grep "app-canary_db" | head -1 | awk '{print $1}')
                
                if [ -z "$DB_CONTAINER_ID" ]; then
                    echo "⚠️ Контейнер не найден по имени, ищем все контейнеры..."
                    echo "Все контейнеры в Swarm:"
                    docker ps --format "table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}" | head -15
                    
                    # Пробуем найти контейнер на всех узлах через задачи сервиса
                    echo "Поиск через задачи сервиса..."
                    TASK_ID=$(docker service ps app-canary_db --filter "desired-state=running" --format "{{.ID}}" | head -1)
                    
                    if [ ! -z "$TASK_ID" ]; then
                        echo "Задача сервиса найдена: $TASK_ID"
                        echo "Информация о задаче:"
                        docker service ps app-canary_db --filter "desired-state=running" --no-trunc
                        
                        # Получаем узел, где запущена задача
                        NODE=$(docker service ps app-canary_db --filter "desired-state=running" --format "{{.Node}}" | head -1)
                        echo "Контейнер запущен на узле: $NODE"
                        
                        # Если контейнер на другом узле, используем Docker API для проверки
                        echo "Контейнер может быть на другом узле Swarm."
                        echo "Проверяем через сетевое подключение (должно работать через Swarm DNS)..."
                    fi
                    
                    # Пробуем подключиться через имя сервиса в сети
                    echo "4. Пробуем подключиться через имя сервиса..."
                    echo "Создаем тестовый контейнер для проверки подключения..."
                    
                    # Простая проверка - можем ли мы подключиться к MySQL через сеть
                    cat > /tmp/test_mysql.sh << "EOF"
#!/bin/bash
echo "Тест подключения к MySQL..."
MAX_ATTEMPTS=10
for i in $(seq 1 $MAX_ATTEMPTS); do
    echo "Попытка $i/$MAX_ATTEMPTS..."
    if mysql -h app-canary_db -u root -prootpassword -e "SELECT 1" 2>/dev/null; then
        echo "✅ Подключение успешно!"
        
        echo "Проверяем базы данных..."
        mysql -h app-canary_db -u root -prootpassword -e "SHOW DATABASES;" 2>&1
        
        echo "Проверяем базу appdb..."
        if mysql -h app-canary_db -u root -prootpassword appdb -e "SHOW TABLES;" 2>/dev/null; then
            echo "✅ База appdb доступна"
            
            TABLES=$(mysql -h app-canary_db -u root -prootpassword appdb -e "SHOW TABLES" 2>/dev/null || echo "")
            echo "Найдены таблицы:"
            echo "$TABLES"
            
            if echo "$TABLES" | grep -q "users" && echo "$TABLES" | grep -q "workouts"; then
                echo "✅ Обе таблицы найдены: users и workouts"
                
                USERS_COUNT=$(mysql -h app-canary_db -u root -prootpassword appdb -e "SELECT COUNT(*) FROM users" --batch --silent 2>/dev/null || echo "0")
                WORKOUTS_COUNT=$(mysql -h app-canary_db -u root -prootpassword appdb -e "SELECT COUNT(*) FROM workouts" --batch --silent 2>/dev/null || echo "0")
                
                echo "Количество пользователей: $USERS_COUNT"
                echo "Количество тренировок: $WORKOUTS_COUNT"
                
                exit 0
            else
                echo "❌ Не все таблицы найдены"
                exit 1
            fi
        else
            echo "❌ База appdb не доступна"
            exit 1
        fi
    else
        echo "⚠️ Не удалось подключиться, ждем 5 секунд..."
        sleep 5
    fi
done
echo "❌ Не удалось подключиться после $MAX_ATTEMPTS попыток"
exit 1
EOF
                    
                    chmod +x /tmp/test_mysql.sh
                    
                    echo "Запускаем тестовый контейнер в сети canary..."
                    docker run --rm --network app-canary_default \
                        -v /tmp/test_mysql.sh:/tmp/test_mysql.sh \
                        --entrypoint /bin/bash \
                        mysql:8.0 \
                        /tmp/test_mysql.sh
                    
                    RESULT=$?
                    rm -f /tmp/test_mysql.sh
                    
                    if [ $RESULT -eq 0 ]; then
                        echo "✅ Проверка БД через сеть успешна!"
                        exit 0
                    else
                        echo "❌ Не удалось проверить БД через сеть"
                        echo "Логи сервиса БД:"
                        docker service logs app-canary_db --tail 20
                        exit 1
                    fi
                else
                    echo "✅ Контейнер найден: $DB_CONTAINER_ID"
                    
                    echo "5. Проверяем, что MySQL процесс работает внутри контейнера..."
                    if docker exec $DB_CONTAINER_ID mysqladmin ping -u root -prootpassword 2>&1; then
                        echo "✅ MySQL процесс работает"
                    else
                        echo "❌ MySQL процесс не работает"
                        echo "Логи контейнера:"
                        docker logs $DB_CONTAINER_ID --tail 10 2>&1
                        exit 1
                    fi
                    
                    echo "6. Проверяем базы данных изнутри контейнера..."
                    docker exec $DB_CONTAINER_ID mysql -u root -prootpassword -e "SHOW DATABASES;" 2>&1
                    
                    echo "7. Проверяем базу данных appdb..."
                    docker exec $DB_CONTAINER_ID mysql -u root -prootpassword -e "USE appdb; SHOW TABLES;" 2>&1
                    
                    echo "8. Проверяем конкретные таблицы..."
                    TABLES=$(docker exec $DB_CONTAINER_ID mysql -u root -prootpassword -e "USE appdb; SHOW TABLES;" --batch --silent 2>/dev/null || echo "")
                    
                    if echo "$TABLES" | grep -q "users" && echo "$TABLES" | grep -q "workouts"; then
                        echo "✅ Обе таблицы найдены: users и workouts"
                        
                        echo "9. Проверяем количество записей..."
                        USERS_COUNT=$(docker exec $DB_CONTAINER_ID mysql -u root -prootpassword -e "USE appdb; SELECT COUNT(*) FROM users;" --batch --silent 2>/dev/null || echo "0")
                        WORKOUTS_COUNT=$(docker exec $DB_CONTAINER_ID mysql -u root -prootpassword -e "USE appdb; SELECT COUNT(*) FROM workouts;" --batch --silent 2>/dev/null || echo "0")
                        
                        echo "Количество пользователей: $USERS_COUNT"
                        echo "Количество тренировок: $WORKOUTS_COUNT"
                        
                        if [ "$USERS_COUNT" -gt "0" ] && [ "$WORKOUTS_COUNT" -gt "0" ]; then
                            echo "✅ Данные успешно загружены!"
                        else
                            echo "⚠️ В таблицах нет данных или не удалось их прочитать"
                        fi
                        
                        echo "✅ ПРОВЕРКА УСПЕШНО ЗАВЕРШЕНА!"
                        exit 0
                    else
                        echo "❌ Не все таблицы найдены"
                        echo "Найдены таблицы:"
                        echo "$TABLES"
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
                        echo "=== Тестирование Canary ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        CANARY_URL="http://192.168.0.1:8081"
                        
                        echo "Тестирование canary по адресу: ${CANARY_URL}"
                        
                        for i in 1 2 3 4 5; do
                            echo ""
                            echo "Тест $i/${TOTAL_TESTS}:"
                            
                            # Пробуем получить главную страницу
                            if curl -f -s --max-time 30 ${CANARY_URL} > /tmp/canary_test_${i}.html 2>/dev/null; then
                                SIZE=$(wc -c < /tmp/canary_test_${i}.html)
                                echo "  ✓ Страница загружена (${SIZE} байт)"
                                
                                # Проверяем на наличие ошибок
                                ERROR_PATTERN="error|fatal|exception|failed|syntax|warning"
                                if ! grep -q -i "${ERROR_PATTERN}" /tmp/canary_test_${i}.html 2>/dev/null; then
                                    SUCCESS=$((SUCCESS + 1))
                                    echo "  ✓ Контент без ошибок"
                                else
                                    echo "  ⚠️ Найдены ошибки в контенте"
                                    grep -i "${ERROR_PATTERN}" /tmp/canary_test_${i}.html 2>/dev/null | head -3
                                fi
                            else
                                CURL_EXIT=$?
                                echo "  ❌ Не удалось загрузить страницу (код: ${CURL_EXIT})"
                            fi
                            
                            sleep 5
                        done
                        
                        echo ""
                        echo "=== Результаты тестирования Canary ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 3 ]; then
                            echo "✅ Canary прошел тестирование!"
                        else
                            echo "❌ Canary не прошел тестирование"
                            echo "Последний ответ сервера:"
                            cat /tmp/canary_test_${TOTAL_TESTS}.html 2>/dev/null | head -50
                            exit 1
                        fi
                    '''
                }
            }
        }
        
        stage('Deploy to Production') {
            steps {
                script {
                    sh '''
                        echo "=== Развертывание в Production ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Проверка текущих сервисов..."
                        docker service ls --filter name=app_
                        
                        echo "2. Обновление web сервиса..."
                        docker service update \
                            --image danil221/php-app:latest \
                            --update-parallelism 1 \
                            --update-delay 10s \
                            --with-registry-auth \
                            app_web
                        
                        echo "3. Ожидание обновления..."
                        sleep 60
                        
                        echo "4. Проверка статуса обновления..."
                        docker service ps app_web --format "table {{.Name}}\t{{.CurrentState}}\t{{.Image}}" | head -10
                        
                        echo "✅ Production обновлен!"
                    '''
                }
            }
        }
        
        stage('Final Verification') {
            steps {
                script {
                    sh '''
                        echo "=== Финальная проверка Production ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        PROD_URL="http://192.168.0.1:80"
                        
                        echo "Тестирование production по адресу: ${PROD_URL}"
                        
                        for i in 1 2 3 4 5; do
                            echo ""
                            echo "Тест $i/${TOTAL_TESTS}:"
                            
                            if curl -f -s --max-time 30 ${PROD_URL} > /tmp/prod_test_${i}.html 2>/dev/null; then
                                SIZE=$(wc -c < /tmp/prod_test_${i}.html)
                                echo "  ✓ Страница загружена (${SIZE} байт)"
                                
                                ERROR_PATTERN="error|fatal|exception|failed|syntax|warning"
                                if ! grep -q -i "${ERROR_PATTERN}" /tmp/prod_test_${i}.html 2>/dev/null; then
                                    SUCCESS=$((SUCCESS + 1))
                                    echo "  ✓ Контент без ошибок"
                                else
                                    echo "  ⚠️ Найдены ошибки в контенте"
                                fi
                            else
                                echo "  ❌ Не удалось загрузить страницу"
                            fi
                            
                            sleep 5
                        done
                        
                        echo ""
                        echo "=== Результаты финальной проверки ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 3 ]; then
                            echo "✅ Production работает корректно!"
                        else
                            echo "❌ Проблемы с production"
                            echo "Проверьте логи:"
                            docker service logs app_web --tail 30 2>/dev/null || true
                            exit 1
                        fi
                    '''
                }
            }
        }
        
        stage('Cleanup Canary') {
            steps {
                script {
                    sh '''
                        echo "=== Удаление Canary ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Удаление canary стека..."
                        docker stack rm app-canary 2>/dev/null || true
                        
                        echo "2. Ожидание удаления..."
                        sleep 20
                        
                        echo "3. Проверка удаления..."
                        if docker stack ls | grep -q app-canary; then
                            echo "⚠️ Canary стек еще существует, повторная попытка..."
                            docker stack rm app-canary 2>/dev/null || true
                            sleep 10
                        fi
                        
                        echo "✅ Canary успешно удален"
                    '''
                }
            }
        }
    }
    
    post {
        always {
            sh '''
                echo "=== Очистка после выполнения ==="
                
                echo "1. Выход из Docker Hub..."
                docker logout 2>/dev/null || true
                
                echo "2. Удаление временных файлов..."
                rm -f docker-compose_canary_temp.yaml 2>/dev/null || true
                rm -f /tmp/canary_*.html /tmp/prod_*.html /tmp/check_db.sql 2>/dev/null || true
                
                echo "✅ Очистка завершена"
            '''
        }
        failure {
            echo '❌ Пайплайн завершился с ошибкой'
            script {
                sh '''
                    echo "=== Аварийная очистка ==="
                    
                    export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                    
                    echo "1. Удаление canary при ошибке..."
                    docker stack rm app-canary 2>/dev/null || true
                    
                    echo "2. Текущие стеки:"
                    docker stack ls 2>/dev/null || true
                    
                    echo "3. Состояние сервисов:"
                    docker service ls 2>/dev/null | head -20
                '''
            }
        }
        success {
            echo '✅ Пайплайн успешно завершен!'
            sh '''
                echo "=== Итоги ==="
                echo "✅ Образы собраны и опубликованы"
                echo "✅ Canary протестирован и удален"
                echo "✅ Production обновлен"
                echo "✅ Все проверки пройдены"
            '''
        }
    }
}
