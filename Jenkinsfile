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
            sh """
                echo "=== Проверка структуры БД Canary ==="
                export DOCKER_HOST="${DOCKER_HOST}"

                echo "1. Ожидание доступности MySQL (mysqladmin ping)..."

                for i in \$(seq 1 30); do
                    docker run --rm \\
                        --network ${CANARY_APP_NAME}_default \\
                        mysql:8.0 \\
                        mysqladmin ping -h db -u root -p${MYSQL_ROOT_PASSWORD} --silent

                    if [ \$? -eq 0 ]; then
                        echo "✅ MySQL доступен"
                        break
                    fi

                    echo "⏳ MySQL ещё не готов... (\$i)"
                    sleep 5
                done

                docker run --rm \\
                    --network ${CANARY_APP_NAME}_default \\
                    mysql:8.0 \\
                    mysqladmin ping -h db -u root -p${MYSQL_ROOT_PASSWORD} --silent

                if [ \$? -ne 0 ]; then
                    echo "❌ MySQL так и не стал доступен"
                    docker service logs ${CANARY_APP_NAME}_db --tail 20 || true
                    exit 1
                fi

                echo "2. Проверка наличия таблиц users и workouts"

                SQL="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${MYSQL_DATABASE}' AND table_name IN ('users','workouts');"

                TABLE_COUNT=\$(docker run --rm \\
                    --network ${CANARY_APP_NAME}_default \\
                    mysql:8.0 \\
                    mysql -h db -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} -N -e "\$SQL")

                echo "Найдено таблиц: \$TABLE_COUNT"

                if [ "\$TABLE_COUNT" -ne 2 ]; then
                    echo "❌ Структура БД некорректна"
                    echo "Фактические таблицы:"
                    docker run --rm \\
                        --network ${CANARY_APP_NAME}_default \\
                        mysql:8.0 \\
                        mysql -h db -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} -e "SHOW TABLES;" || true
                    exit 1
                fi

                echo "✅ Структура БД корректна"
            """
        }
    }
}



        stage('Canary Testing') {
            steps {
                script {
                    sh '''
                        echo "=== Тестирование Canary (порт 8081) ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "Даем дополнительное время для запуска PHP..."
                        sleep 30
                        
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
                                echo "  Проверяем доступность порта..."
                                timeout 5 nc -z 192.168.0.1 8081 && echo "  Порт 8081 открыт" || echo "  Порт 8081 закрыт"
                            fi
                            
                            sleep 5
                        done
                        
                        echo ""
                        echo "=== Результаты тестирования Canary ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 3 ]; then
                            echo "✅ Canary прошел тестирование!"
                            echo "Веб-сервис работает на порту 8081"
                            
                            # Проверяем логи веб-сервиса
                            echo "Логи веб-сервиса:"
                            docker service logs app-canary_web-server --tail 5 2>/dev/null || true
                        else
                            echo "❌ Canary не прошел тестирование"
                            echo "Последний ответ сервера:"
                            cat /tmp/canary_test_${TOTAL_TESTS}.html 2>/dev/null | head -50 || echo "(нет данных)"
                            echo ""
                            echo "Логи веб-сервиса:"
                            docker service logs app-canary_web-server --tail 20 2>/dev/null || true
                            echo ""
                            echo "Логи БД:"
                            docker service logs app-canary_db --tail 10 2>/dev/null || true
                            exit 1
                        fi
                    '''
                }
            }
        }
        
        // ВТОРОЙ ЭТАП 'Canary Testing' УДАЛЕН - ЭТО БЫЛ ДУБЛЬ
        
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
