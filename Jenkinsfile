pipeline {
    agent {
        label 'docker-agent'
    }
    
    environment {
        // Основные настройки
        APP_NAME = 'app'
        CANARY_APP_NAME = 'app-canary'
        DOCKER_HUB_USER = 'danil221'
        GIT_REPO = 'https://github.com/d-maksimov/crudapp.git'
        
        // Имена образов
        BACKEND_IMAGE_NAME = 'php-app'
        DATABASE_IMAGE_NAME = 'mysql-app'
        
        // Сетевые настройки
        MANAGER_IP = '192.168.0.1'
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
        
        // Настройки базы данных
        MYSQL_ROOT_PASSWORD = 'rootpassword'
        MYSQL_APP_PASSWORD = 'userpassword'
        MYSQL_DATABASE = 'appdb'  // Используем 'appdb' как в init.sql
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
                        docker build -f php.Dockerfile . -t \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:\${BUILD_NUMBER}
                        
                        echo "2. Сборка MySQL образа..."
                        docker build -f mysql.Dockerfile . -t \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:\${BUILD_NUMBER}
                        
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
                            
                            # Логин в Docker Hub
                            echo "\${DOCKER_PASS}" | docker login -u "\${DOCKER_USER}" --password-stdin
                            
                            # Пушим образы с номером сборки
                            echo "1. Публикация PHP образа с тегом \${BUILD_NUMBER}..."
                            docker push \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:\${BUILD_NUMBER}
                            
                            echo "2. Публикация MySQL образа с тегом \${BUILD_NUMBER}..."
                            docker push \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:\${BUILD_NUMBER}
                            
                            # Добавляем тег latest
                            echo "3. Добавление тега latest..."
                            docker tag \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:\${BUILD_NUMBER} \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:latest
                            docker tag \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:\${BUILD_NUMBER} \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:latest
                            
                            # Пушим latest теги
                            echo "4. Публикация тегов latest..."
                            docker push \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:latest
                            docker push \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:latest
                            
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
                        
                        # Экспортируем DOCKER_HOST для работы с Swarm
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        # Удаляем старый canary стек если есть
                        echo "1. Очистка предыдущего canary..."
                        docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                        sleep 15
                        
                        # Подготавливаем docker-compose файл для canary
                        echo "2. Подготовка docker-compose для canary..."
                        cp docker-compose_canary.yaml docker-compose_canary_temp.yaml
                        sed -i "s/\\\${BUILD_NUMBER}/\${BUILD_NUMBER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/\${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        
                        echo "3. Проверка конфигурации:"
                        echo "---"
                        grep "image:" docker-compose_canary_temp.yaml
                        echo "---"
                        
                        # Разворачиваем canary
                        echo "4. Развертывание canary стека..."
                        docker stack deploy -c docker-compose_canary_temp.yaml ${CANARY_APP_NAME} --with-registry-auth
                        
                        # Ждем запуска сервисов
                        echo "5. Ожидание запуска canary сервисов..."
                        TIMEOUT=180  # 3 минуты
                        START_TIME=\$(date +%s)
                        
                        while true; do
                            CURRENT_TIME=\$(date +%s)
                            ELAPSED=\$((CURRENT_TIME - START_TIME))
                            
                            if [ \$ELAPSED -ge \$TIMEOUT ]; then
                                echo "❌ Таймаут ожидания запуска canary"
                                echo "Статус сервисов:"
                                docker service ls --filter name=${CANARY_APP_NAME}
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
                        
                        # Дополнительное время для инициализации БД
                        echo "6. Ожидание инициализации базы данных..."
                        sleep 45
                        
                        echo "✅ Canary развернут на порту 8081"
                    """
                }
            }
        }
        
        stage('Check Database Structure') {
            steps {
                script {
                    sh """
                        echo "=== Проверка структуры базы данных ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        # SQL для проверки таблиц
                        cat > /tmp/check_db.sql << 'EOF'
-- Проверяем наличие таблиц
SELECT 
    COUNT(CASE WHEN table_name = 'users' THEN 1 END) as has_users,
    COUNT(CASE WHEN table_name = 'workouts' THEN 1 END) as has_workouts
FROM information_schema.tables 
WHERE table_schema = 'appdb';
EOF
                        
                        # Пробуем подключиться несколько раз
                        echo "Проверка структуры БД canary..."
                        for attempt in {1..6}; do
                            echo "Попытка \$attempt из 6..."
                            
                            # Проверяем доступность БД
                            if docker run --rm --network ${CANARY_APP_NAME}_default \\
                                mysql:8.0 mysql -h ${CANARY_APP_NAME}_db -u root -p\${MYSQL_ROOT_PASSWORD} \\
                                -e "SELECT 1;" --connect-timeout=15 2>/dev/null; then
                                
                                echo "✅ Подключение к БД успешно"
                                
                                # Проверяем таблицы
                                RESULT=\$(docker run --rm --network ${CANARY_APP_NAME}_default \\
                                    -v /tmp/check_db.sql:/tmp/check_db.sql \\
                                    mysql:8.0 mysql -h ${CANARY_APP_NAME}_db -u root -p\${MYSQL_ROOT_PASSWORD} appdb \\
                                    -e "source /tmp/check_db.sql" --batch --silent 2>/dev/null || echo "0 0")
                                
                                HAS_USERS=\$(echo \$RESULT | awk '{print \$1}')
                                HAS_WORKOUTS=\$(echo \$RESULT | awk '{print \$2}')
                                
                                echo "Результат проверки:"
                                echo "  Таблица 'users': \$HAS_USERS"
                                echo "  Таблица 'workouts': \$HAS_WORKOUTS"
                                
                                if [ "\$HAS_USERS" = "1" ] && [ "\$HAS_WORKOUTS" = "1" ]; then
                                    echo "✅ Структура БД корректна"
                                    rm -f /tmp/check_db.sql
                                    exit 0
                                else
                                    echo "❌ Проблема со структурой БД"
                                    echo "Проверьте init.sql файл"
                                    rm -f /tmp/check_db.sql
                                    exit 1
                                fi
                            else
                                echo "⏳ БД еще не готова, ждем 15 секунд..."
                                sleep 15
                            fi
                        done
                        
                        echo "❌ Не удалось проверить структуру БД"
                        rm -f /tmp/check_db.sql
                        exit 1
                    """
                }
            }
        }
        
        stage('Canary Testing') {
            steps {
                script {
                    sh """
                        echo "=== Тестирование Canary ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        CANARY_URL="http://${MANAGER_IP}:8081"
                        
                        echo "Тестирование canary по адресу: \${CANARY_URL}"
                        
                        for i in \$(seq 1 \${TOTAL_TESTS}); do
                            echo ""
                            echo "Тест \$i/\${TOTAL_TESTS}:"
                            
                            # Пробуем получить главную страницу
                            if curl -f -s --max-time 30 \${CANARY_URL} > /tmp/canary_test_\${i}.html 2>/dev/null; then
                                SIZE=\$(wc -c < /tmp/canary_test_\${i}.html)
                                echo "  ✓ Страница загружена (\${SIZE} байт)"
                                
                                # Проверяем на наличие ошибок
                                if ! grep -q -i "error\|fatal\|exception\|failed\|syntax\|warning" /tmp/canary_test_\${i}.html 2>/dev/null; then
                                    SUCCESS=\$((SUCCESS + 1))
                                    echo "  ✓ Контент без ошибок"
                                else
                                    echo "  ⚠️ Найдены ошибки в контенте"
                                    grep -i "error\|fatal\|exception" /tmp/canary_test_\${i}.html 2>/dev/null | head -3
                                fi
                            else
                                CURL_EXIT=\$?
                                echo "  ❌ Не удалось загрузить страницу (код: \${CURL_EXIT})"
                            fi
                            
                            sleep 5
                        done
                        
                        echo ""
                        echo "=== Результаты тестирования Canary ==="
                        echo "Успешных тестов: \${SUCCESS}/\${TOTAL_TESTS}"
                        
                        if [ \${SUCCESS} -ge 3 ]; then
                            echo "✅ Canary прошел тестирование!"
                        else
                            echo "❌ Canary не прошел тестирование"
                            echo "Последний ответ сервера:"
                            cat /tmp/canary_test_\${TOTAL_TESTS}.html 2>/dev/null | head -50
                            exit 1
                        fi
                    """
                }
            }
        }
        
        stage('Deploy to Production') {
            steps {
                script {
                    sh """
                        echo "=== Развертывание в Production ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        echo "1. Проверка текущих сервисов..."
                        docker service ls --filter name=app_
                        
                        echo "2. Обновление web сервиса..."
                        docker service update \\
                            --image \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:latest \\
                            --update-parallelism 1 \\
                            --update-delay 10s \\
                            --with-registry-auth \\
                            app_web
                        
                        echo "3. Ожидание обновления..."
                        sleep 60
                        
                        echo "4. Проверка статуса обновления..."
                        docker service ps app_web --format "table {{.Name}}\\t{{.CurrentState}}\\t{{.Image}}" | head -10
                        
                        echo "✅ Production обновлен!"
                    """
                }
            }
        }
        
        stage('Final Verification') {
            steps {
                script {
                    sh """
                        echo "=== Финальная проверка Production ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        PROD_URL="http://${MANAGER_IP}:80"
                        
                        echo "Тестирование production по адресу: \${PROD_URL}"
                        
                        for i in \$(seq 1 \${TOTAL_TESTS}); do
                            echo ""
                            echo "Тест \$i/\${TOTAL_TESTS}:"
                            
                            if curl -f -s --max-time 30 \${PROD_URL} > /tmp/prod_test_\${i}.html 2>/dev/null; then
                                SIZE=\$(wc -c < /tmp/prod_test_\${i}.html)
                                echo "  ✓ Страница загружена (\${SIZE} байт)"
                                
                                if ! grep -q -i "error\|fatal\|exception\|failed\|syntax\|warning" /tmp/prod_test_\${i}.html 2>/dev/null; then
                                    SUCCESS=\$((SUCCESS + 1))
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
                        echo "Успешных тестов: \${SUCCESS}/\${TOTAL_TESTS}"
                        
                        if [ \${SUCCESS} -ge 3 ]; then
                            echo "✅ Production работает корректно!"
                        else
                            echo "❌ Проблемы с production"
                            echo "Проверьте логи:"
                            docker service logs app_web --tail 30 2>/dev/null || true
                            exit 1
                        fi
                    """
                }
            }
        }
        
        stage('Cleanup Canary') {
            steps {
                script {
                    sh """
                        echo "=== Удаление Canary ==="
                        
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        echo "1. Удаление canary стека..."
                        docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                        
                        echo "2. Ожидание удаления..."
                        sleep 20
                        
                        echo "3. Проверка удаления..."
                        if docker stack ls | grep -q ${CANARY_APP_NAME}; then
                            echo "⚠️ Canary стек еще существует, повторная попытка..."
                            docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                            sleep 10
                        fi
                        
                        echo "✅ Canary успешно удален"
                    """
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
                    
                    export DOCKER_HOST="${DOCKER_HOST}" 2>/dev/null || true
                    
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
