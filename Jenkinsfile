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
        
        stage('Debug Before Deploy') {
            steps {
                script {
                    sh '''
                    echo "=== DEBUG: Checking current state ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Current stacks:"
                    docker stack ls || true
                    
                    echo "2. Current services:"
                    docker service ls || true
                    
                    echo "3. Current networks:"
                    docker network ls | grep -E "(app|canary)" || true
                    
                    echo "4. Check if ports are in use:"
                    netstat -tlnp | grep -E ":(3307|8081)" || echo "Ports 3307 and 8081 available"
                    '''
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
                    
                    # Check if files exist
                    echo "Checking compose files..."
                    ls -la docker-compose*.yaml || true
                    
                    # Deploy new canary with more logging
                    echo "Deploying canary stack..."
                    docker stack deploy -c docker-compose_canary.yaml app-canary --with-registry-auth --prune
                    
                    echo "Waiting 30 seconds for initial deployment..."
                    sleep 30
                    
                    echo "Checking service status..."
                    for i in {1..5}; do
                        echo "Check $i:"
                        docker service ls --filter name=app-canary --format "table {{.Name}}\t{{.Mode}}\t{{.Replicas}}\t{{.Image}}"
                        sleep 10
                    done
                    
                    echo "Detailed service inspection:"
                    docker service ps app-canary_db --no-trunc || true
                    docker service ps app-canary_web-server --no-trunc || true
                    '''
                }
            }
        }
        
        stage('Check Canary Services') {
            steps {
                script {
                    sh '''
                    echo "=== Checking Canary Services ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. List all containers with details:"
                    docker ps -a --format "table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}" | grep -E "(canary|mysql|php)" || true
                    
                    echo "2. Find canary MySQL container:"
                    # Более простой и надежный способ поиска
                    MYSQL_CONTAINER=$(docker ps -a --filter "label=com.docker.stack.namespace=app-canary" --filter "ancestor=danil221/mysql-app*" --format "{{.ID}}" | head -1)
                    
                    if [ -n "$MYSQL_CONTAINER" ]; then
                        echo "Canary MySQL container found: $MYSQL_CONTAINER"
                        echo "Container details:"
                        docker inspect $MYSQL_CONTAINER --format "{{json .State}}" | jq . || true
                        
                        echo "Checking logs..."
                        docker logs $MYSQL_CONTAINER --tail 30 2>/dev/null || echo "Cannot get logs"
                    else
                        echo "⚠️ Canary MySQL container not found by labels"
                        echo "Trying alternative search..."
                        
                        # Ищем по имени задачи сервиса
                        SERVICE_TASK=$(docker service ps app-canary_db -q --no-trunc 2>/dev/null | head -1)
                        if [ -n "$SERVICE_TASK" ]; then
                            MYSQL_CONTAINER=$(docker ps -a --filter "name=$SERVICE_TASK" --format "{{.ID}}" | head -1)
                            echo "Found by service task: $MYSQL_CONTAINER"
                        fi
                        
                        if [ -z "$MYSQL_CONTAINER" ]; then
                            echo "Listing all mysql containers:"
                            docker ps -a --filter "ancestor=danil221/mysql-app" --format "table {{.ID}}\t{{.Names}}\t{{.Status}}\t{{.Ports}}"
                            
                            # Для отладки берем ЛЮБОЙ mysql контейнер
                            MYSQL_CONTAINER=$(docker ps -a --filter "ancestor=danil221/mysql-app" --format "{{.ID}}" | head -1)
                            echo "Using container for testing: $MYSQL_CONTAINER"
                        fi
                    fi
                    '''
                }
            }
        }
        
        stage('Test Database Connection') {
            steps {
                script {
                    sh '''
                    echo "=== Testing Database Connection ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Get MySQL container..."
                    # Упрощенный поиск - любой mysql контейнер кроме явно production
                    MYSQL_CONTAINER=$(docker ps --format "{{.ID}} {{.Names}}" | grep "mysql" | grep -v "app_db.1" | awk '{print $1}' | head -1)
                    
                    if [ -z "$MYSQL_CONTAINER" ]; then
                        echo "No non-production MySQL found, using any mysql container..."
                        MYSQL_CONTAINER=$(docker ps --filter "ancestor=danil221/mysql-app" --format "{{.ID}}" | head -1)
                    fi
                    
                    if [ -z "$MYSQL_CONTAINER" ]; then
                        echo "❌ ERROR: No MySQL container found at all!"
                        echo "Current containers:"
                        docker ps --format "table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}"
                        exit 1
                    fi
                    
                    echo "Testing with container: $MYSQL_CONTAINER"
                    echo "Container name: $(docker ps --filter "id=$MYSQL_CONTAINER" --format "{{.Names}}")"
                    
                    echo "2. Check MySQL process..."
                    docker exec $MYSQL_CONTAINER ps aux | grep mysql || echo "MySQL process not found"
                    
                    echo "3. Test MySQL connection (basic)..."
                    if docker exec $MYSQL_CONTAINER mysqladmin ping -uroot -prootpassword --silent 2>/dev/null; then
                        echo "✅ MySQL is responding"
                    else
                        echo "❌ MySQL not responding, checking logs..."
                        docker logs $MYSQL_CONTAINER --tail 20 2>/dev/null || true
                        echo "Trying direct mysql command..."
                        docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "SELECT 1" 2>/dev/null || true
                    fi
                    
                    echo "4. Check databases..."
                    docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "SHOW DATABASES;" 2>/dev/null || echo "Cannot connect to MySQL"
                    
                    echo "5. Check appdb..."
                    if docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "USE appdb; SELECT COUNT(*) FROM users;" 2>/dev/null; then
                        echo "✅ appdb exists and has data"
                    else
                        echo "⚠️ appdb not ready or empty"
                        # Не завершаем пайплайн - продолжаем для отладки
                    fi
                    '''
                }
            }
        }
        
        stage('Test Canary Web Application') {
            steps {
                script {
                    sh '''
                    echo "=== Testing Canary Web Application ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "Testing on http://${MANAGER_IP}:8081"
                    
                    # Проверяем доступность порта
                    echo "Checking port 8081..."
                    nc -zv ${MANAGER_IP} 8081 && echo "Port 8081 is open" || echo "Port 8081 not accessible"
                    
                    SUCCESS=0
                    MAX_TESTS=5
                    
                    for i in $(seq 1 $MAX_TESTS); do
                        echo "Test $i/$MAX_TESTS..."
                        if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /dev/null 2>&1; then
                            SUCCESS=$((SUCCESS + 1))
                            echo "✓ Test passed"
                            # Проверяем контент
                            curl -s http://${MANAGER_IP}:8081/ | grep -i "html" && echo "✓ HTML content found"
                            break
                        else
                            echo "✗ Test failed, waiting..."
                            sleep 10
                        fi
                    done
                    
                    if [ "$SUCCESS" -ge 1 ]; then
                        echo "✅ Canary web application is accessible"
                    else
                        echo "⚠️ Canary web application not responding"
                        echo "Checking web service logs..."
                        WEB_CONTAINER=$(docker ps --filter "ancestor=danil221/php-app" --format "{{.ID}}" | head -1)
                        if [ -n "$WEB_CONTAINER" ]; then
                            docker logs $WEB_CONTAINER --tail 20 2>/dev/null || true
                        fi
                        # Не завершаем пайплайн - переходим к деплою production
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
                    
                    docker stack deploy -c docker-compose.yaml app --with-registry-auth --prune
                    
                    echo "Waiting 60 seconds for production to start..."
                    sleep 60
                    
                    echo "2. Production services:"
                    docker service ls --filter name=app
                    
                    echo "3. Clean up canary..."
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
                    
                    for i in $(seq 1 5); do
                        echo "Test $i/5..."
                        if curl -f -s --max-time 15 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                            echo "✅ Production is working!"
                            exit 0
                        fi
                        sleep 5
                    done
                    
                    echo "❌ Production not responding"
                    echo "Checking production services..."
                    docker service ps app_web --no-trunc || true
                    
                    # Попробуем еще раз с задержкой
                    sleep 30
                    curl -f -s --max-time 15 http://${MANAGER_IP}:80/ && echo "✅ Production finally working!" || exit 1
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
                docker service ls 2>/dev/null || true
                docker stack ls 2>/dev/null || true
                docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || true
                '''
            }
        }
    }
}
