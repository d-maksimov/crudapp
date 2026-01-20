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
                            echo "\${DOCKER_PASS}" | docker login -u "\${DOCKER_USER}" --password-stdin
                            
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
                    
                    sleep(time: 60, unit: 'SECONDS')
                    
                    sh """
                        echo "Проверка наличия таблиц 'users' и 'workouts'..."
                        
                        # 1. Ищем сервис canary БД
                        echo "Поиск сервиса app-canary_db..."
                        
                        # Используем Docker Host из environment
                        export DOCKER_HOST="${DOCKER_HOST}"
                        
                        SERVICE_INFO=\$(docker service ls --filter name=app-canary_db --format "{{.ID}} {{.Name}} {{.Replicas}}" 2>/dev/null)
                        
                        if [ -z "\$SERVICE_INFO" ]; then
                            echo "❌ Сервис app-canary_db не найден"
                            echo "Все сервисы:"
                            docker service ls
                            exit 1
                        fi
                        
                        echo "Сервис найден: \$SERVICE_INFO"
                        
                        # 2. Проверяем через временный контейнер mysql в сети canary
                        echo "Запуск тестового контейнера для проверки БД..."
                        
                        # Создаем временный скрипт для проверки
                        cat > /tmp/check_tables.sql << 'EOF'
SELECT 
    SUM(CASE WHEN table_name = 'users' THEN 1 ELSE 0 END) as has_users,
    SUM(CASE WHEN table_name = 'workouts' THEN 1 ELSE 0 END) as has_workouts
FROM information_schema.tables 
WHERE table_schema = '${MYSQL_DATABASE}';
EOF
                        
                        # Выполняем проверку через временный контейнер
                        CHECK_RESULT=\$(docker run --rm \
                            --network app-canary_default \
                            mysql:8.0 \
                            mysql -h app-canary_db -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} \
                            -e "source /tmp/check_tables.sql" \
                            --batch --silent 2>/dev/null || echo "0 0")
                        
                        HAS_USERS=\$(echo \$CHECK_RESULT | awk '{print \$1}')
                        HAS_WORKOUTS=\$(echo \$CHECK_RESULT | awk '{print \$2}')
                        
                        echo "Результат проверки:"
                        echo "Таблица 'users': \$HAS_USERS (1 = есть, 0 = нет)"
                        echo "Таблица 'workouts': \$HAS_WORKOUTS (1 = есть, 0 = нет)"
                        
                        if [ "\$HAS_USERS" = "1" ] && [ "\$HAS_WORKOUTS" = "1" ]; then
                            echo "✅ Обе таблицы существуют: users и workouts"
                            echo "✅ Структура БД корректна"
                        elif [ "\$HAS_USERS" = "1" ] && [ "\$HAS_WORKOUTS" = "0" ]; then
                            echo "❌ ОШИБКА: Отсутствует таблица 'workouts'"
                            echo "Проверьте init.sql файл"
                            exit 1
                        elif [ "\$HAS_USERS" = "0" ] && [ "\$HAS_WORKOUTS" = "1" ]; then
                            echo "❌ ОШИБКА: Отсутствует таблица 'users'"
                            echo "Проверьте init.sql файл"
                            exit 1
                        else
                            echo "❌ ОШИБКА: Отсутствуют обе таблицы"
                            echo "Проверьте init.sql файл"
                            exit 1
                        fi
                        
                        # Удаляем временный файл
                        rm -f /tmp/check_tables.sql
                    """
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
                            if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /tmp/canary_\$i.html 2>/dev/null && \\
                               ! grep -iq "error\|fatal\|exception\|failed" /tmp/canary_\$i.html 2>/dev/null; then
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
                        docker service update --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest app_web --with-registry-auth
                        docker service update --image ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest app_db --with-registry-auth
                        
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
                            if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /tmp/prod_\$i.html 2>/dev/null && \\
                               ! grep -iq "error\|fatal\|exception\|failed" /tmp/prod_\$i.html 2>/dev/null; then
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
                        docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
                        sleep 10
                        echo "Canary удалён"
                    """
                }
            }
        }
    }
    
    post {
        always {
            sh 'docker logout 2>/dev/null || true'
            sh 'rm -f docker-compose_canary_temp.yaml /tmp/canary_*.html /tmp/prod_*.html /tmp/check_tables.sql 2>/dev/null || true'
        }
        failure {
            echo '✗ Ошибка в пайплайне'
            script {
                sh '''
                    docker stack rm app-canary 2>/dev/null || true
                    echo "Canary удалён при ошибке"
                '''
            }
        }
        success {
            echo '✅ Пайплайн успешно завершён!'
        }
    }
}
