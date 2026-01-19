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
                    echo '=== Проверка структуры базы данных ==='
                    
                    // ПРЯМОЙ ПОИСК CANARY КОНТЕЙНЕРА ПО СЕРВИСУ
                    def dbContainer = sh(script: """
                        # Ищем контейнер canary БД
                        # Сначала по метке сервиса
                        CONTAINER=\$(docker ps -q --filter "label=com.docker.swarm.service.name=${CANARY_APP_NAME}_db" 2>/dev/null | head -1)
                        
                        # Если не нашли, ищем по образу + имени canary
                        if [ -z "\$CONTAINER" ]; then
                            CONTAINER=\$(docker ps --format "{{.ID}}\t{{.Names}}" | grep -i "canary.*db\\|${CANARY_APP_NAME}.*db" | head -1 | cut -f1)
                        fi
                        
                        # Если всё ещё не нашли, ищем по образу нового тега
                        if [ -z "\$CONTAINER" ]; then
                            CONTAINER=\$(docker ps -q --filter "ancestor=${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}" | head -1)
                        fi
                        
                        echo "\$CONTAINER"
                    """, returnStdout: true).trim()
                    
                    if (!dbContainer) {
                        // Если контейнер не найден, ищем через сервис
                        echo "Контейнер не найден напрямую, проверяем сервис..."
                        def serviceStatus = sh(script: """
                            docker service ls --filter name=${CANARY_APP_NAME}_db --format "{{.Replicas}}"
                        """, returnStdout: true).trim()
                        
                        if (serviceStatus != "1/1") {
                            error "❌ Canary БД не запущена. Статус сервиса: ${serviceStatus}"
                        } else {
                            error "❌ Canary БД сервис запущен, но контейнер не найден. Проверьте docker ps"
                        }
                    }
                    
                    echo "✅ Canary БД контейнер найден: ${dbContainer}"
                    
                    // Ждём пока БД станет healthy
                    for (int i = 1; i <= 20; i++) {
                        def health = sh(script: """
                            docker inspect --format='{{.State.Health.Status}}' ${dbContainer} 2>/dev/null || echo 'unknown'
                        """, returnStdout: true).trim()
                        
                        if (health == 'healthy') {
                            echo "✅ Canary БД здорова"
                            break
                        } else if (health == 'unhealthy') {
                            error "❌ Canary БД нездорова"
                        }
                        
                        if (i == 20) {
                            echo "⚠️ БД всё ещё не healthy, но продолжаем проверку..."
                        } else {
                            echo "⏳ Ожидание health БД (${i}/20)... Статус: ${health}"
                            sleep 5
                        }
                    }
                    
                    // Проверяем структуру БД
                    def tablesCount = sh(script: """
                        docker exec ${dbContainer} mysql -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} -e "
                            SELECT COUNT(*) FROM information_schema.tables 
                            WHERE table_schema = DATABASE() 
                            AND table_name IN ('users', 'workouts');
                        " --batch --silent 2>/dev/null || echo "0"
                    """, returnStdout: true).trim()
                    
                    echo "Найдено таблиц: ${tablesCount}"
                    
                    if (tablesCount == '2') {
                        echo '✅ Canary БД корректна (2 таблицы)'
                    } else if (tablesCount == '1') {
                        error "❌ Canary БД повреждена (найдено таблиц: ${tablesCount}/2) - отсутствует одна таблица"
                    } else if (tablesCount == '0') {
                        error "❌ Canary БД повреждена (найдено таблиц: ${tablesCount}/2) - таблицы не найдены"
                    } else {
                        error "❌ Canary БД повреждена (найдено таблиц: ${tablesCount}/2)"
                    }
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
