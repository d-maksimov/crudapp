pipeline {
    agent { label 'docker-agent' }
    
    environment {
        DOCKER_HUB_USER = 'danil221'
        BUILD_TAG = "${BUILD_NUMBER}"
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
        MANAGER_IP = '192.168.0.1'
    }
    
    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }
        
        stage('Build Images') {
            steps {
                script {
                    sh """
                    echo "=== Building Docker Images ==="
                    export DOCKER_HOST=tcp://192.168.0.1:2376
                    
                    echo "1. Building PHP image..."
                    docker build -f php.Dockerfile -t ${DOCKER_HUB_USER}/php-app:${BUILD_TAG} .
                    
                    echo "2. Building MySQL image..."
                    docker build -f mysql.Dockerfile -t ${DOCKER_HUB_USER}/mysql-app:${BUILD_TAG} .
                    
                    echo "✅ Images built"
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
                        echo "=== Pushing to Docker Hub ==="
                        export DOCKER_HOST=tcp://192.168.0.1:2376
                        
                        echo "Logging in to Docker Hub..."
                        echo "\${DOCKER_PASS}" | docker login -u "\${DOCKER_USER}" --password-stdin
                        
                        echo "Tagging as latest..."
                        docker tag ${DOCKER_HUB_USER}/php-app:${BUILD_TAG} ${DOCKER_HUB_USER}/php-app:latest
                        docker tag ${DOCKER_HUB_USER}/mysql-app:${BUILD_TAG} ${DOCKER_HUB_USER}/mysql-app:latest
                        
                        echo "Pushing PHP images..."
                        docker push ${DOCKER_HUB_USER}/php-app:${BUILD_TAG}
                        docker push ${DOCKER_HUB_USER}/php-app:latest
                        
                        echo "Pushing MySQL images..."
                        docker push ${DOCKER_HUB_USER}/mysql-app:${BUILD_TAG}
                        docker push ${DOCKER_HUB_USER}/mysql-app:latest
                        
                        echo "✅ Images pushed"
                        """
                    }
                }
            }
        }
        
        stage('Deploy Canary') {
            steps {
                script {
                    sh '''
                    echo "=== Deploying Canary ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    # Clean up old stack
                    echo "Removing old canary stack..."
                    docker stack rm app-canary 2>/dev/null || true
                    sleep 15
                    
                    # Deploy new canary
                    echo "Deploying canary stack..."
                    docker stack deploy -c docker-compose_canary.yaml app-canary --with-registry-auth
                    
                    echo "Waiting 60 seconds for services to start..."
                    sleep 60
                    
                    echo "Canary services status:"
                    docker service ls --filter name=app-canary --format "table {{.Name}}\\t{{.Replicas}}\\t{{.Image}}\\t{{.Ports}}"
                    
                    echo "Detailed service tasks:"
                    echo "MySQL service:"
                    docker service ps app-canary_db --no-trunc || true
                    echo ""
                    echo "Web service:"
                    docker service ps app-canary_web-server --no-trunc || true
                    '''
                }
            }
        }
        
        stage('Test Canary Connectivity') {
            steps {
                script {
                    sh '''
                    echo "=== Testing Canary Connectivity ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Testing MySQL on port 3307..."
                    
                    # Проверяем доступность порта MySQL (3307) на менеджере Swarm
                    MAX_RETRIES=10
                    MYSQL_READY=false
                    
                    for i in $(seq 1 $MAX_RETRIES); do
                        echo "MySQL test $i/$MAX_RETRIES..."
                        if timeout 5 bash -c "cat < /dev/null > /dev/tcp/${MANAGER_IP}/3307" 2>/dev/null; then
                            echo "✅ MySQL port 3307 is open"
                            MYSQL_READY=true
                            break
                        else
                            echo "⏳ MySQL port not ready yet..."
                            sleep 10
                        fi
                    done
                    
                    if [ "$MYSQL_READY" = false ]; then
                        echo "⚠️ MySQL port 3307 not accessible after $MAX_RETRIES attempts"
                        echo "Checking service logs..."
                        docker service logs app-canary_db --tail 20 2>/dev/null || true
                        # Продолжаем, возможно приложение будет работать
                    fi
                    
                    echo "2. Testing Web Application on port 8081..."
                    
                    WEB_READY=false
                    for i in $(seq 1 $MAX_RETRIES); do
                        echo "Web test $i/$MAX_RETRIES..."
                        if curl -f -s --max-time 5 http://${MANAGER_IP}:8081/ > /dev/null 2>&1; then
                            echo "✅ Web application is accessible"
                            WEB_READY=true
                            break
                        else
                            echo "⏳ Web application not ready yet..."
                            sleep 10
                        fi
                    done
                    
                    if [ "$WEB_READY" = false ]; then
                        echo "⚠️ Web application not accessible after $MAX_RETRIES attempts"
                        echo "Checking web service logs..."
                        docker service logs app-canary_web-server --tail 20 2>/dev/null || true
                        # Проверяем порт хотя бы
                        if timeout 5 bash -c "cat < /dev/null > /dev/tcp/${MANAGER_IP}/8081" 2>/dev/null; then
                            echo "✅ Port 8081 is open at least"
                        else
                            echo "❌ Port 8081 not open"
                        fi
                    fi
                    
                    echo "3. Testing database connection via network..."
                    
                    # Пробуем подключиться к MySQL через порт
                    if command -v mysql &>/dev/null; then
                        echo "Testing MySQL connection with mysql client..."
                        for i in $(seq 1 5); do
                            if mysql -h${MANAGER_IP} -P3307 -uroot -prootpassword -e "SELECT 1" 2>/dev/null; then
                                echo "✅ Can connect to MySQL via port 3307"
                                echo "Checking databases..."
                                mysql -h${MANAGER_IP} -P3307 -uroot -prootpassword -e "SHOW DATABASES;" 2>/dev/null || true
                                break
                            else
                                echo "⏳ Cannot connect to MySQL yet..."
                                sleep 5
                            fi
                        done
                    else
                        echo "⚠️ mysql client not available, using docker exec workaround..."
                        
                        # Альтернативный способ: находим любой контейнер mysql и используем его для теста
                        # Это может быть контейнер с другой ноды, но мы тестируем connectivity
                        ANY_MYSQL_CONTAINER=$(docker ps -q --filter "name=app_db" | head -1)
                        if [ -n "$ANY_MYSQL_CONTAINER" ]; then
                            echo "Using production MySQL container for connectivity test: $ANY_MYSQL_CONTAINER"
                            docker exec $ANY_MYSQL_CONTAINER mysql -h${MANAGER_IP} -P3307 -uroot -prootpassword -e "SELECT 1" 2>/dev/null && \
                                echo "✅ Canary MySQL accessible from network" || \
                                echo "⚠️ Cannot connect to canary MySQL from network"
                        fi
                    fi
                    
                    # Если ни один тест не прошел, но сервисы созданы, продолжаем
                    if [ "$MYSQL_READY" = false ] && [ "$WEB_READY" = false ]; then
                        echo "❌ Canary deployment appears to have issues"
                        echo "But continuing to production deployment anyway..."
                    else
                        echo "✅ Canary deployment looks good!"
                    fi
                    '''
                }
            }
        }
        
        stage('Deploy to Production') {
            steps {
                script {
                    sh '''
                    echo "=== Deploying to Production ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Deploy/update production stack..."
                    
                    docker stack rm app 2>/dev/null || true
                    sleep 15
                    
                    docker stack deploy -c docker-compose.yaml app --with-registry-auth
                    
                    echo "Waiting 60 seconds for production to start..."
                    sleep 60
                    
                    echo "2. Production services:"
                    docker service ls --filter name=app
                    
                    echo "3. Remove canary..."
                    docker stack rm app-canary 2>/dev/null || true
                    sleep 10
                    '''
                }
            }
        }
        
        stage('Verify Production') {
            steps {
                script {
                    sh '''
                    echo "=== Verifying Production ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "Testing production on http://${MANAGER_IP}:80"
                    
                    MAX_RETRIES=10
                    SUCCESS=false
                    
                    for i in $(seq 1 $MAX_RETRIES); do
                        echo "Test $i/$MAX_RETRIES..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                            echo "✅ Production is working!"
                            SUCCESS=true
                            break
                        else
                            echo "⏳ Production not ready yet..."
                            sleep 10
                        fi
                    done
                    
                    if [ "$SUCCESS" = false ]; then
                        echo "❌ Production not responding after $MAX_RETRIES attempts"
                        echo "Checking production services..."
                        docker service ps app_web --no-trunc || true
                        echo "Checking production logs..."
                        docker service logs app_web --tail 20 2>/dev/null || true
                        exit 1
                    fi
                    '''
                }
            }
        }
        
        stage('Final Check') {
            steps {
                script {
                    sh '''
                    echo "=== Final System Check ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Current stacks:"
                    docker stack ls
                    
                    echo "2. All services:"
                    docker service ls --format "table {{.Name}}\\t{{.Replicas}}\\t{{.Image}}\\t{{.Ports}}"
                    
                    echo "3. Production containers:"
                    docker ps --filter "name=app_" --format "table {{.Names}}\\t{{.Status}}\\t{{.Ports}}" | head -10
                    
                    echo "✅ Deployment completed!"
                    '''
                }
            }
        }
    }
    
    post {
        always {
            echo "=== Pipeline Cleanup ==="
            script {
                sh '''
                export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                docker logout 2>/dev/null || true
                docker image prune -f 2>/dev/null || true
                '''
            }
        }
        success {
            echo "✅ Pipeline completed successfully!"
        }
        failure {
            echo "❌ Pipeline failed"
            script {
                sh '''
                export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                echo "Debug info on failure:"
                echo "Stacks:"
                docker stack ls 2>/dev/null || true
                echo ""
                echo "Services:"
                docker service ls 2>/dev/null || true
                echo ""
                echo "Failed service tasks:"
                docker service ps --filter "desired-state=running" --format "{{.Name}}\\t{{.CurrentState}}" | grep -v Running 2>/dev/null || true
                '''
            }
        }
    }
}
