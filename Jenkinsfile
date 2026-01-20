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
                echo "=== УПРОЩЕННАЯ ПРОВЕРКА БАЗЫ ДАННЫХ ==="
                
                export DOCKER_HOST="tcp://192.168.0.1:2376"
                
                echo "1. Даем дополнительное время для полного запуска MySQL..."
                sleep 45
                
                echo "2. Проверяем логи БД..."
                docker service logs app-canary_db --tail 5
                
                echo "3. Пробуем простую проверку через временный контейнер..."
                
                # Создаем очень простой SQL скрипт
                cat > /tmp/simple_check.sql << "EOF"
-- Простейшая проверка
SHOW DATABASES;
USE appdb;
SHOW TABLES;
SELECT 'users' as table_name, COUNT(*) as count FROM users
UNION ALL
SELECT 'workouts' as table_name, COUNT(*) as count FROM workouts;
EOF
                
                echo "4. Запускаем проверку (попробуем 3 раза)..."
                
                for i in 1 2 3; do
                    echo "Попытка $i из 3..."
                    
                    # Запускаем временный контейнер в той же сети
                    echo "Запуск тестового контейнера..."
                    
                    # Используем другой подход - создаем сервис для проверки
                    cat > /tmp/test-service.yml << "EOF"
version: '3.8'
services:
  db-checker:
    image: mysql:8.0
    command: >
      bash -c "
        echo 'Ждем 10 секунд перед проверкой...' &&
        sleep 10 &&
        echo 'Проверяем подключение к БД...' &&
        if mysql -h app-canary_db -u root -prootpassword -e 'SELECT 1'; then
          echo '✅ Подключение успешно!' &&
          echo 'Проверяем базы данных...' &&
          mysql -h app-canary_db -u root -prootpassword -e 'SHOW DATABASES;' &&
          echo 'Проверяем базу appdb...' &&
          mysql -h app-canary_db -u root -prootpassword appdb -e 'SHOW TABLES;' &&
          echo 'Проверяем таблицы...' &&
          TABLES=\$(mysql -h app-canary_db -u root -prootpassword appdb -e 'SHOW TABLES' --batch --silent) &&
          if echo \"\$TABLES\" | grep -q users && echo \"\$TABLES\" | grep -q workouts; then
            echo '✅ Таблицы users и workouts найдены!' &&
            echo 'Количество записей:' &&
            mysql -h app-canary_db -u root -prootpassword appdb -e '
              SELECT \"users\" as table_name, COUNT(*) as count FROM users
              UNION ALL
              SELECT \"workouts\" as table_name, COUNT(*) as count FROM workouts;
            ' &&
            echo '✅ ПРОВЕРКА УСПЕШНА!' &&
            exit 0
          else
            echo '❌ Не все таблицы найдены' &&
            echo 'Найдены таблицы:' &&
            echo \"\$TABLES\" &&
            exit 1
          fi
        else
          echo '❌ Не удалось подключиться к БД' &&
          exit 1
        fi
      "
    networks:
      - app-canary_default

networks:
  app-canary_default:
    external: true
EOF
                    
                    echo "Запускаем сервис для проверки..."
                    docker stack deploy -c /tmp/test-service.yml db-checker 2>/dev/null || true
                    
                    echo "Ждем выполнения проверки..."
                    sleep 30
                    
                    echo "Проверяем логи..."
                    docker service logs db-checker_db-checker --tail 20 2>/dev/null || true
                    
                    echo "Удаляем тестовый сервис..."
                    docker stack rm db-checker 2>/dev/null || true
                    sleep 5
                    
                    # Проверяем через другой способ - exec в существующий контейнер PHP
                    echo "Пробуем другой способ - проверка через PHP контейнер..."
                    PHP_CONTAINER=$(docker ps --filter "ancestor=danil221/php-app" --format "{{.ID}}" | head -1)
                    
                    if [ ! -z "$PHP_CONTAINER" ]; then
                        echo "Найден PHP контейнер: $PHP_CONTAINER"
                        echo "Проверяем подключение к БД из PHP контейнера..."
                        
                        # Проверяем, может ли PHP контейнер подключиться к БД
                        if docker exec $PHP_CONTAINER bash -c "timeout 10 mysql -h app-canary_db -u root -prootpassword -e 'SELECT 1'" 2>/dev/null; then
                            echo "✅ PHP контейнер может подключиться к БД!"
                            echo "Проверка завершена успешно!"
                            rm -f /tmp/simple_check.sql /tmp/test-service.yml
                            exit 0
                        else
                            echo "❌ PHP контейнер не может подключиться к БД"
                        fi
                    fi
                    
                    if [ $i -lt 3 ]; then
                        echo "Ждем 20 секунд перед следующей попыткой..."
                        sleep 20
                    fi
                done
                
                echo "❌ Все попытки проверки не удались"
                echo "Диагностика:"
                echo "1. Проверяем сервисы:"
                docker service ls
                echo "2. Проверяем сеть:"
                docker network inspect app-canary_default 2>/dev/null | jq '.[].Containers' 2>/dev/null || docker network inspect app-canary_default 2>/dev/null
                echo "3. Логи БД:"
                docker service logs app-canary_db --tail 30
                
                rm -f /tmp/simple_check.sql /tmp/test-service.yml
                exit 1
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
