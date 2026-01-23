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
                    
                    echo "Waiting 90 seconds for services to start (MySQL needs time for init)..."
                    sleep 90
                    
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
        
        stage('Test Canary Database') {
            steps {
                script {
                    sh '''
                    echo "=== Testing Canary Database ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Find canary MySQL container..."
                    
                    # Находим контейнер canary MySQL
                    MYSQL_CONTAINER=$(docker ps -q --filter "name=app-canary_db" --filter "status=running" | head -1)
                    
                    if [ -z "$MYSQL_CONTAINER" ]; then
                        echo "Trying alternative search..."
                        # Ищем по сервису
                        SERVICE_TASK=$(docker service ps app-canary_db -q --no-trunc 2>/dev/null | head -1)
                        if [ -n "$SERVICE_TASK" ]; then
                            MYSQL_CONTAINER=$(docker ps -q --filter "name=$SERVICE_TASK" | head -1)
                        fi
                    fi
                    
                    if [ -z "$MYSQL_CONTAINER" ]; then
                        echo "⚠️ Cannot find canary MySQL container, checking all MySQL containers..."
                        docker ps --filter "ancestor=danil221/mysql-app" --format "table {{.Names}}\\t{{.Status}}"
                        exit 1
                    fi
                    
                    echo "Found canary MySQL container: $MYSQL_CONTAINER"
                    
                    echo "2. Check MySQL is running inside container..."
                    if docker exec $MYSQL_CONTAINER mysqladmin ping -uroot -prootpassword --silent 2>/dev/null; then
                        echo "✅ MySQL is running"
                    else
                        echo "❌ MySQL not responding"
                        echo "Checking logs..."
                        docker logs $MYSQL_CONTAINER --tail 20 2>/dev/null || true
                        exit 1
                    fi
                    
                    echo "3. Check all databases..."
                    docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "SHOW DATABASES;" 2>/dev/null || echo "Cannot show databases"
                    
                    echo "4. Check appdb database exists..."
                    if docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "USE appdb; SELECT 1;" 2>/dev/null; then
                        echo "✅ appdb database exists"
                    else
                        echo "❌ appdb database not found"
                        exit 1
                    fi
                    
                    echo "5. Check tables in appdb..."
                    TABLES=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SHOW TABLES;" 2>/dev/null || echo "")
                    
                    echo "Tables found:"
                    echo "$TABLES"
                    
                    if [ -n "$TABLES" ]; then
                        TABLE_COUNT=$(echo "$TABLES" | wc -l)
                        echo "Total tables: $TABLE_COUNT"
                        
                        # Проверяем наличие конкретных таблиц
                        if echo "$TABLES" | grep -q "users" && echo "$TABLES" | grep -q "workouts"; then
                            echo "✅ Required tables (users, workouts) exist"
                            
                            # Проверяем структуру таблиц
                            echo "Checking table structure..."
                            
                            echo "Users table structure:"
                            docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -e "DESCRIBE users;" 2>/dev/null || echo "Cannot describe users table"
                            
                            echo "Workouts table structure:"
                            docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -e "DESCRIBE workouts;" 2>/dev/null || echo "Cannot describe workouts table"
                            
                            # Проверяем есть ли данные
                            echo "Checking if tables have data..."
                            USERS_COUNT=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")
                            WORKOUTS_COUNT=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SELECT COUNT(*) FROM workouts;" 2>/dev/null || echo "0")
                            
                            echo "Users count: $USERS_COUNT"
                            echo "Workouts count: $WORKOUTS_COUNT"
                            
                        else
                            echo "❌ Required tables missing!"
                            echo "Expected tables: users, workouts"
                            echo "Found tables: $TABLES"
                            exit 1
                        fi
                    else
                        echo "❌ No tables found in appdb!"
                        exit 1
                    fi
                    
                    echo "✅ Database validation completed successfully!"
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
                    
                    MAX_RETRIES=10
                    SUCCESS=false
                    
                    for i in $(seq 1 $MAX_RETRIES); do
                        echo "Test $i/$MAX_RETRIES..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:8081/ > /dev/null 2>&1; then
                            echo "✅ Web application is accessible"
                            
                            # Дополнительная проверка - ищем HTML или конкретный контент
                            echo "Checking content..."
                            if curl -s http://${MANAGER_IP}:8081/ | grep -i "html" > /dev/null 2>&1; then
                                echo "✅ HTML content found"
                            fi
                            
                            # Проверяем что приложение может подключиться к базе
                            echo "Testing database connectivity from web app..."
                            if curl -s http://${MANAGER_IP}:8081/ | grep -i "error\|exception\|failed" > /dev/null 2>&1; then
                                echo "⚠️ Possible errors in web application"
                            else
                                echo "✅ Web app appears healthy"
                            fi
                            
                            SUCCESS=true
                            break
                        else
                            echo "⏳ Web application not ready yet..."
                            sleep 10
                        fi
                    done
                    
                    if [ "$SUCCESS" = false ]; then
                        echo "❌ Web application not accessible after $MAX_RETRIES attempts"
                        echo "Checking web service logs..."
                        docker service logs app-canary_web-server --tail 30 2>/dev/null || true
                        exit 1
                    fi
                    
                    echo "✅ Canary web application tested successfully!"
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
        
        stage('Verify Production Database') {
            steps {
                script {
                    sh '''
                    echo "=== Verifying Production Database ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Find production MySQL container..."
                    
                    MYSQL_CONTAINER=$(docker ps -q --filter "name=app_db" --filter "status=running" | head -1)
                    
                    if [ -z "$MYSQL_CONTAINER" ]; then
                        echo "⚠️ Cannot find production MySQL container"
                        docker ps --filter "ancestor=danil221/mysql-app" --format "table {{.Names}}\\t{{.Status}}"
                        exit 1
                    fi
                    
                    echo "Found production MySQL container: $MYSQL_CONTAINER"
                    
                    echo "2. Check production database..."
                    
                    # Проверяем подключение
                    if docker exec $MYSQL_CONTAINER mysqladmin ping -uroot -prootpassword --silent 2>/dev/null; then
                        echo "✅ Production MySQL is running"
                    else
                        echo "❌ Production MySQL not responding"
                        exit 1
                    fi
                    
                    # Проверяем базу и таблицы
                    echo "3. Check appdb and tables..."
                    
                    if docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "USE appdb; SHOW TABLES;" 2>/dev/null; then
                        echo "✅ Production appdb exists with tables"
                        
                        # Проверяем конкретные таблицы
                        TABLES=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SHOW TABLES;" 2>/dev/null)
                        echo "Tables in production: $TABLES"
                        
                        if echo "$TABLES" | grep -q "users" && echo "$TABLES" | grep -q "workouts"; then
                            echo "✅ Production has required tables"
                        else
                            echo "⚠️ Production missing some tables"
                        fi
                    else
                        echo "❌ Production appdb issues"
                        exit 1
                    fi
                    
                    echo "✅ Production database verified!"
                    '''
                }
            }
        }
        
        stage('Verify Production Web') {
            steps {
                script {
                    sh '''
                    echo "=== Verifying Production Web ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "Testing production on http://${MANAGER_IP}:80"
                    
                    MAX_RETRIES=10
                    SUCCESS=false
                    
                    for i in $(seq 1 $MAX_RETRIES); do
                        echo "Test $i/$MAX_RETRIES..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                            echo "✅ Production is working!"
                            SUCCESS=true
                            
                            # Проверяем контент
                            echo "Checking production content..."
                            if curl -s http://${MANAGER_IP}:80/ | grep -i "html" > /dev/null 2>&1; then
                                echo "✅ Production HTML content found"
                            fi
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
                    
                    echo "✅ Deployment completed successfully!"
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
