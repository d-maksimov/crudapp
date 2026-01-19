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
        DATABASE_IMAGE_NAME = 'mysql'
        MANAGER_IP = '192.168.0.1'
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
                        echo "Используем php.Dockerfile и mysql.Dockerfile"
                        
                        # Собираем PHP образ
                        docker build -f php.Dockerfile . -t ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                        
                        # Собираем MySQL образ
                        docker build -f mysql.Dockerfile . -t ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                    """
                }
            }
        }
        
        stage('Push to Docker Hub') {
            steps {
                withCredentials([string(credentialsId: 'docker-hub-credentials', variable: 'DOCKER_PASS')]) {
                    sh """
                        echo "=== Отправка образов в Docker Hub ==="
                        echo "${DOCKER_PASS}" | docker login -u ${DOCKER_HUB_USER} --password-stdin
                        
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
                        for i in \$(seq 1 20); do
                            echo "Проверка \$i/20..."
                            docker service ls --filter name=${CANARY_APP_NAME}
                            sleep 5
                        done
                        sleep 10
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
                        TESTS=5
                        
                        for i in \$(seq 1 \$TESTS); do
                            echo "Тест \$i/\$TESTS..."
                            if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /tmp/canary_\$i.html && \
                               ! grep -iq "error\\\\|fatal\\\\|exception\\\\|failed" /tmp/canary_\$i.html; then
                                SUCCESS=\$((SUCCESS + 1))
                                echo "✓ Тест \$i пройден"
                            else
                                echo "✗ Тест \$i не пройден"
                            fi
                            sleep 3
                        done
                        
                        echo "Успешных тестов: \$SUCCESS/\$TESTS"
                        if [ \$SUCCESS -ge 3 ]; then
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
                        TESTS=5
                        
                        for i in \$(seq 1 \$TESTS); do
                            echo "Тест \$i/\$TESTS..."
                            if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /tmp/prod_\$i.html && \
                               ! grep -iq "error\\\\|fatal\\\\|exception\\\\|failed" /tmp/prod_\$i.html; then
                                SUCCESS=\$((SUCCESS + 1))
                                echo "✓ Тест \$i пройден"
                            else
                                echo "✗ Тест \$i не пройден"
                            fi
                            sleep 3
                        done
                        
                        echo "Успешных тестов production: \$SUCCESS/\$TESTS"
                        
                        if [ \$SUCCESS -ge 3 ]; then
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
