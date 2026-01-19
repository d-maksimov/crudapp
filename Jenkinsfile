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
        MYSQL_ROOT_PASSWORD = 'rootpassword'  // Пароль из вашего Dockerfile
        MYSQL_DATABASE = 'appdb'              // Имя БД из вашего Dockerfile
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
                        # Создаем временный файл с подставленными значениями
                        sed "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary.yaml > docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        
                        # Разворачиваем canary
                        docker stack deploy -c docker-compose_canary_temp.yaml ${CANARY_APP_NAME} --with-registry-auth
                        
                        echo "Ожидание запуска canary сервисов..."
                        for i in \$(seq 1 15); do
                            echo "Проверка \$i/15..."
                            docker service ls --filter name=${CANARY_APP_NAME}
                            sleep 5
                        done
                        sleep 10
                    """
                }
            }
        }
        
        // ========== НОВЫЙ ЭТАП: ПРОВЕРКА СТРУКТУРЫ БАЗЫ ДАННЫХ ==========
       stage('Check Database Structure') {
    steps {
        script {
            sh '''
                echo "=== Проверка структуры базы данных ==="
                
                # Даем время на запуск
                sleep 30
                
                # Ищем контейнер canary БД (БЕЗ фильтра status=running)
                DB_CONTAINER=$(docker ps -q -f name=app-canary_db)
                
                if [ -z "$DB_CONTAINER" ]; then
                    echo "⚠️ Контейнер canary БД не найден"
                    echo "Проверяем, есть ли вообще контейнеры с MySQL..."
                    docker ps -a | grep mysql || true
                    
                    # Проверяем production БД для демонстрации
                    echo "Проверяем production БД для демонстрации логики проверки:"
                    PROD_CONTAINER=$(docker ps -q -f name=app_db)
                    if [ -n "$PROD_CONTAINER" ]; then
                        echo "Production БД: $PROD_CONTAINER"
                        TABLES_COUNT=$(docker exec $PROD_CONTAINER \\
                            mysql -u root -prootpassword appdb -e \\
                            "SELECT COUNT(*) FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name IN ('users', 'workouts');" --batch --silent 2>/dev/null || echo "0")
                        
                        echo "В production БД найдено таблиц: $TABLES_COUNT/2"
                        
                        if [ "$TABLES_COUNT" -eq 2 ]; then
                            echo "✅ Production БД корректна (2 таблицы)"
                        else
                            echo "❌ Production БД неполная ($TABLES_COUNT/2 таблиц)"
                        fi
                    fi
                    
                    exit 1
                fi
                
                echo "Контейнер canary БД найден: $DB_CONTAINER"
                
                # Проверяем таблицы
                TABLES_COUNT=$(docker exec $DB_CONTAINER \\
                    mysql -u root -prootpassword appdb -e \\
                    "SELECT COUNT(*) FROM information_schema.tables 
                     WHERE table_schema = DATABASE() 
                     AND table_name IN ('users', 'workouts');" --batch --silent 2>/dev/null || echo "0")
                
                echo "Найдено таблиц в canary БД: $TABLES_COUNT/2"
                
                # ОЖИДАЕМ 1 таблицу (только users), так как workouts удалили из init.sql
                if [ "$TABLES_COUNT" -eq "1" ]; then
                    echo "❌ ОБНАРУЖЕНА ПРОБЛЕМА: Отсутствует таблица 'workouts'!"
                    echo "   Canary БД содержит только 1 из 2 обязательных таблиц"
                    echo "   -> Pipeline будет остановлен"
                    echo "   -> Canary deployment будет откатан"
                    exit 1
                elif [ "$TABLES_COUNT" -eq "2" ]; then
                    echo "✅ Canary БД корректна (2 таблицы)"
                    echo "   -> Pipeline продолжит работу"
                else
                    echo "⚠️ Неожиданное количество таблиц: $TABLES_COUNT"
                    exit 1
                fi
            '''
        }
    }
}
        // ========== КОНЕЦ НОВОГО ЭТАПА ==========
        
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
