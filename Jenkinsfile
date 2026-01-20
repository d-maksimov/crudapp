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
        MYSQL_DATABASE = 'appdb'
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
    }
    
    stages {
        stage('Checkout') {
            steps {
                git branch: 'main', url: "${GIT_REPO}"
            }
        }
        
        stage('Build Docker Images') {
            steps {
                script {
                    sh """
                        echo "=== Сборка Docker образов ==="
                        docker build -f php.Dockerfile . -t ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                        docker build -f mysql.Dockerfile . -t ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                    """
                }
            }
        }
        
        stage('Push to Docker Hub') {
            steps {
                withCredentials([usernamePassword(credentialsId: 'docker-hub-credentials', 
                                               usernameVariable: 'DOCKER_USER', 
                                               passwordVariable: 'DOCKER_PASS')]) {
                    script {
                        sh """
                            echo "=== Отправка образов в Docker Hub ==="
                            echo "${DOCKER_PASS}" | docker login -u "${DOCKER_USER}" --password-stdin
                            
                            # Пушим образы с номером сборки
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            # Тегируем как latest и пушим
                            docker tag ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker tag ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                        """
                    }
                }
            }
        }
        
        stage('Deploy Canary') {
            steps {
                script {
                    sh """
                        echo "=== Развёртывание Canary (1 реплика) ==="
                        
                        # Удаляем старый стек если есть
                        docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                        sleep 15
                        
                        # Создаем временный файл с подставленными значениями
                        sed "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary.yaml > docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        
                        # Проверяем что получилось
                        echo "=== Проверка подстановки ==="
                        grep "image:" docker-compose_canary_temp.yaml
                        
                        # Разворачиваем canary
                        docker stack deploy -c docker-compose_canary_temp.yaml ${CANARY_APP_NAME} --with-registry-auth
                        
                        echo "Ожидание запуска canary сервисов (MySQL может запускаться до 90 секунд)..."
                        for i in \$(seq 1 24); do  # 24 * 5 = 120 секунд
                            echo "Проверка \$i/24..."
                            docker service ls --filter name=${CANARY_APP_NAME}
                            
                            # Проверяем что сервисы запущены
                            RUNNING=\$(docker service ls --filter name=${CANARY_APP_NAME} --format "{{.Replicas}}" | grep -o "1/1" | wc -l)
                            if [ "\$RUNNING" -eq 2 ]; then
                                echo "✅ Оба canary сервиса запущены"
                                break
                            fi
                            
                            sleep 5
                        done
                        
                        echo "Дополнительное ожидание для healthcheck БД..."
                        sleep 30
                    """
                }
            }
        }
        
       stage('Check Database Structure') {
    steps {
        script {
            echo '=== Проверка таблиц базы данных ==='
            
            sleep 60
            
            sh '''
                echo "Проверка наличия таблиц 'users' и 'workouts'..."
                
                # 1. Ищем контейнер с ТЕКУЩИМ образом (с номером сборки)
                CURRENT_IMAGE="danil221/mysql-app:${BUILD_NUMBER}"
                echo "Ищем контейнер с образом: $CURRENT_IMAGE"
                
                CONTAINER_ID=$(docker ps --filter "ancestor=$CURRENT_IMAGE" --format "{{.ID}}" | head -1)
                
                # 2. Если не нашли, ищем контейнер из стека app-canary
                if [ -z "$CONTAINER_ID" ]; then
                    echo "Ищем контейнер в стеке app-canary..."
                    CONTAINER_ID=$(docker ps --filter "name=app-canary" --format "{{.ID}}" | head -1)
                fi
                
                # 3. Если все еще не нашли, показываем все контейнеры и ищем mysql
                if [ -z "$CONTAINER_ID" ]; then
                    echo "=== Все контейнеры ==="
                    docker ps --format "table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}"
                    
                    # Ищем любой mysql контейнер (последний запущенный)
                    CONTAINER_ID=$(docker ps --filter "ancestor=danil221/mysql-app" --format "{{.ID}}" | head -1)
                fi
                
                if [ -z "$CONTAINER_ID" ]; then
                    echo "❌ Контейнер БД не найден"
                    echo "Ожидался образ: $CURRENT_IMAGE"
                    echo "Доступные MySQL контейнеры:"
                    docker ps --filter "ancestor=mysql" --format "table {{.ID}}\t{{.Names}}\t{{.Image}}"
                    exit 1
                fi
                
                CONTAINER_NAME=$(docker inspect --format '{{.Name}}' $CONTAINER_ID)
                CONTAINER_IMAGE=$(docker inspect --format '{{.Config.Image}}' $CONTAINER_ID)
                
                echo "Найден контейнер: $CONTAINER_NAME"
                echo "Образ: $CONTAINER_IMAGE"
                echo "Статус: $(docker inspect --format '{{.State.Status}}' $CONTAINER_ID)"
                
                # 4. Проверяем подключение к БД
                echo "Проверка подключения к БД..."
                MAX_RETRIES=15
                for i in $(seq 1 $MAX_RETRIES); do
                    if docker exec $CONTAINER_ID mysql -u root -prootpassword -e "SELECT 1;" 2>/dev/null; then
                        echo "✅ Успешное подключение к БД (попытка $i/$MAX_RETRIES)"
                        break
                    fi
                    
                    if [ $i -eq $MAX_RETRIES ]; then
                        echo "❌ Не удалось подключиться к БД после $MAX_RETRIES попыток"
                        echo "Логи контейнера:"
                        docker logs $CONTAINER_ID --tail 30
                        exit 1
                    fi
                    
                    echo "⏳ Ожидание готовности БД ($i/$MAX_RETRIES)..."
                    sleep 4
                done
                
                # 5. Проверяем наличие базы данных appdb
                echo "Проверка наличия базы данных 'appdb'..."
                if docker exec $CONTAINER_ID mysql -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null | grep -q "appdb"; then
                    echo "✅ База данных 'appdb' существует"
                else
                    echo "❌ База данных 'appdb' не найдена"
                    exit 1
                fi
                
                # 6. Проверяем наличие обеих таблиц
                echo "Проверка наличия таблиц 'users' и 'workouts'..."
                
                # Получаем список всех таблиц
                TABLES_LIST=$(docker exec $CONTAINER_ID mysql -u root -prootpassword appdb -e "SHOW TABLES;" --batch --silent 2>/dev/null || echo "")
                
                echo "Все таблицы в БД appdb:"
                echo "$TABLES_LIST" | while read table; do echo "  - $table"; done
                
                # Проверяем наличие конкретных таблиц
                HAS_USERS=$(echo "$TABLES_LIST" | grep -c "^users$" || true)
                HAS_WORKOUTS=$(echo "$TABLES_LIST" | grep -c "^workouts$" || true)
                
                echo "Таблица 'users': $([ "$HAS_USERS" -eq 1 ] && echo "НАЙДЕНА" || echo "НЕ НАЙДЕНА")"
                echo "Таблица 'workouts': $([ "$HAS_WORKOUTS" -eq 1 ] && echo "НАЙДЕНА" || echo "НЕ НАЙДЕНА")"
                
                # 7. Детальная проверка
                if [ "$HAS_USERS" -eq 1 ] && [ "$HAS_WORKOUTS" -eq 1 ]; then
                    echo "✅ Обе таблицы существуют: users и workouts"
                    
                    # Проверяем структуру
                    echo "Структура таблицы 'users':"
                    docker exec $CONTAINER_ID mysql -u root -prootpassword appdb -e "DESCRIBE users;" 2>/dev/null || echo "Не удалось получить структуру"
                    
                    echo "Структура таблицы 'workouts':"
                    docker exec $CONTAINER_ID mysql -u root -prootpassword appdb -e "DESCRIBE workouts;" 2>/dev/null || echo "Не удалось получить структуру"
                    
                    echo "✅ Структура БД корректна"
                    
                elif [ "$HAS_USERS" -eq 1 ] && [ "$HAS_WORKOUTS" -eq 0 ]; then
                    echo "❌ ОШИБКА: Отсутствует таблица 'workouts'"
                    echo "Проверьте init.sql файл - таблица workouts должна быть создана"
                    exit 1
                elif [ "$HAS_USERS" -eq 0 ] && [ "$HAS_WORKOUTS" -eq 1 ]; then
                    echo "❌ ОШИБКА: Отсутствует таблица 'users'"
                    echo "Проверьте init.sql файл - таблица users должна быть создана"
                    exit 1
                else
                    echo "❌ ОШИБКА: Отсутствуют обе таблицы: users и workouts"
                    echo "Проверьте init.sql файл - должны быть созданы обе таблицы"
                    exit 1
                fi
                
                echo "✅ Проверка БД завершена успешно"
            '''
        }
    }
}
        
        stage('Canary Testing') {
            steps {
                script {
                    sh """
                        echo "=== Тестирование Canary-версии (порт 8081) ==="
                        SUCCESS=0
                        TESTS=3
                        
                        for i in \$(seq 1 \$TESTS); do
                            echo "Тест \$i/\$TESTS..."
                            if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /tmp/canary_\$i.html && \\
                               ! grep -iq "error\\\\|fatal\\\\|exception\\\\|failed" /tmp/canary_\$i.html; then
                                SUCCESS=\$((SUCCESS + 1))
                                echo "✓ Тест \$i пройден"
                            else
                                echo "✗ Тест \$i не пройден"
                            fi
                            sleep 2
                        done
                        
                        echo "Успешных тестов: \$SUCCESS/\$TESTS"
                        if [ \$SUCCESS -ge 2 ]; then
                            echo "✓ Canary прошёл тестирование!"
                        else
                            echo "✗ Canary не прошёл тестирование!"
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
                        echo "=== Развертывание Production ==="
                        # Обновляем production сервисы
                        docker service update --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest app_web
                        docker service update --image ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest app_db
                        
                        echo "Ожидание обновления..."
                        sleep 30
                        
                        echo "Статус после обновления:"
                        docker service ps app_web | head -10
                        echo "Production развернут!"
                    """
                }
            }
        }
        
        stage('Final Verification') {
            steps {
                script {
                    sh """
                        echo "=== Финальная проверка Production (порт 80) ==="
                        SUCCESS=0
                        TESTS=3
                        
                        for i in \$(seq 1 \$TESTS); do
                            echo "Тест \$i/\$TESTS..."
                            if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /tmp/prod_\$i.html && \\
                               ! grep -iq "error\\\\|fatal\\\\|exception\\\\|failed" /tmp/prod_\$i.html; then
                                SUCCESS=\$((SUCCESS + 1))
                                echo "✓ Тест \$i пройден"
                            else
                                echo "✗ Тест \$i не пройден"
                            fi
                            sleep 2
                        done
                        
                        echo "Успешных тестов production: \$SUCCESS/\$TESTS"
                        
                        if [ \$SUCCESS -ge 2 ]; then
                            echo "✓ Финальная проверка пройдена!"
                            echo "✓ Production работает на порту 80!"
                        else
                            echo "✗ Production не отвечает на порту 80"
                            echo "Проверка сервисов:"
                            docker service ls
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
                        docker stack rm ${CANARY_APP_NAME} || true
                        echo "Canary удалён"
                    """
                }
            }
        }
    }
    
    post {
        always {
            sh 'docker logout || true'
            sh 'rm -f docker-compose_canary_temp.yaml /tmp/canary_*.html /tmp/prod_*.html || true'
        }
        failure {
            echo '✗ Ошибка в пайплайне'
            sh '''
                docker stack rm app-canary || true
                echo "Canary удалён при ошибке"
            '''
        }
    }
}
