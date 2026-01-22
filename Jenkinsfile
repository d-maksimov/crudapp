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
                        
                        echo "Pushing PHP image..."
                        docker push ${DOCKER_HUB_USER}/php-app:${BUILD_TAG}
                        
                        echo "Pushing MySQL image..."
                        docker push ${DOCKER_HUB_USER}/mysql-app:${BUILD_TAG}
                        
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
                    docker stack rm app-canary 2>/dev/null || true
                    sleep 10
                    
                    # Deploy new canary
                    docker stack deploy -c docker-compose_canary.yaml app-canary --with-registry-auth
                    
                    echo "Waiting 90 seconds for services to start..."
                    sleep 90
                    
                    echo "Canary services:"
                    docker service ls --filter name=app-canary
                    '''
                }
            }
        }
        
       stage('Check MySQL Container') {
    steps {
        script {
            sh '''
            echo "=== Checking MySQL Container ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            echo "1. Find MySQL container (Swarm naming)..."
            
            # В Swarm контейнеры именуются как <service_name>.<replica_number>.<random_id>
            # Попробуем разные варианты поиска
            MYSQL_CONTAINER=$(docker ps -q --filter "name=app-canary_db" --filter "name=.*db.*")
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "Trying alternative search..."
                # Ищем все контейнеры и фильтруем по названию образа
                MYSQL_CONTAINER=$(docker ps --format "{{.ID}} {{.Image}}" | grep "mysql-app" | awk '{print $1}' | head -1)
            fi
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "Trying service tasks..."
                # Получаем задачи сервиса
                SERVICE_TASK=$(docker service ps app-canary_db --format "{{.Name}}" --no-trunc 2>/dev/null | head -1)
                if [ -n "$SERVICE_TASK" ]; then
                    MYSQL_CONTAINER=$(docker ps --filter "name=$SERVICE_TASK" --format "{{.ID}}" | head -1)
                fi
            fi
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "❌ MySQL container not found with filters!"
                echo "Listing ALL containers to debug..."
                docker ps --format "table {{.ID}}\\t{{.Names}}\\t{{.Image}}\\t{{.Status}}"
                echo ""
                echo "Trying to find by image..."
                docker ps --filter "ancestor=danil221/mysql-app" --format "table {{.ID}}\\t{{.Names}}\\t{{.Image}}\\t{{.Status}}"
                exit 1
            fi
            
            echo "MySQL container found: $MYSQL_CONTAINER"
            
            echo "2. Check container status..."
            docker ps --filter "id=$MYSQL_CONTAINER" --format "table {{.Names}}\\t{{.Status}}\\t{{.Image}}"
            
            echo "3. Check MySQL logs (last 20 lines)..."
            docker logs $MYSQL_CONTAINER --tail 20 2>/dev/null || echo "Cannot get logs"
            '''
        }
    }
}
        
          stage('Test Database Inside Container') {
    steps {
        script {
            sh '''
            echo "=== Testing Database Inside Container ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            echo "1. Find the CORRECT MySQL container..."
            
            # Находим контейнер по имени образа и статусу
            MYSQL_CONTAINER=$(docker ps --format "{{.ID}} {{.Names}} {{.Image}} {{.Status}}" | grep "mysql-app" | grep -v "app_db" | awk '{print $1}' | head -1)
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "Trying different search - look for 'canary' in name..."
                MYSQL_CONTAINER=$(docker ps --format "{{.ID}} {{.Names}}" | grep -i canary | awk '{print $1}' | head -1)
            fi
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "Looking for any MySQL container except production..."
                # Ищем все контейнеры mysql-app и исключаем те, что в статусе "healthy" (это продакшен)
                MYSQL_CONTAINER=$(docker ps --format "{{.ID}} {{.Names}} {{.Status}}" | grep "mysql-app" | grep -v "healthy" | awk '{print $1}' | head -1)
            fi
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "⚠️ No canary MySQL container found by filters"
                echo "Listing ALL containers:"
                docker ps --format "table {{.ID}}\\t{{.Names}}\\t{{.Image}}\\t{{.Status}}"
                
                # Берем ЛЮБОЙ контейнер mysql-app кроме явно продакшенного
                ALL_MYSQL=$(docker ps --filter "ancestor=danil221/mysql-app" --format "{{.ID}} {{.Names}}")
                echo "All mysql-app containers: $ALL_MYSQL"
                
                # Берем первый не-"app_db" контейнер
                MYSQL_CONTAINER=$(echo "$ALL_MYSQL" | grep -v "app_db" | awk '{print $1}' | head -1)
            fi
            
            if [ -z "$MYSQL_CONTAINER" ]; then
                echo "❌ Cannot find canary MySQL container"
                exit 1
            fi
            
            echo "Using MySQL container: $MYSQL_CONTAINER"
            echo "Container details:"
            docker ps --filter "id=$MYSQL_CONTAINER" --format "table {{.Names}}\\t{{.Status}}\\t{{.Image}}"
            
            echo "2. Test basic MySQL connection..."
            if docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "SELECT 1" 2>/dev/null; then
                echo "✅ MySQL is running inside container"
            else
                echo "❌ MySQL not responding inside container"
                echo "Checking logs..."
                docker logs $MYSQL_CONTAINER --tail 10 2>/dev/null || true
                exit 1
            fi
            
            echo "3. Check all databases..."
            docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "SHOW DATABASES;" 2>/dev/null || echo "Cannot show databases"
            
            echo "4. Check appdb specifically..."
            if docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword -e "USE appdb; SHOW TABLES;" 2>/dev/null; then
                echo "✅ appdb database exists with tables"
                
                # Check specific tables
                USERS_EXISTS=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='appdb' AND table_name='users';" 2>/dev/null || echo "0")
                WORKOUTS_EXISTS=$(docker exec $MYSQL_CONTAINER mysql -uroot -prootpassword appdb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='appdb' AND table_name='workouts';" 2>/dev/null || echo "0")
                
                echo "Users table: $USERS_EXISTS"
                echo "Workouts table: $WORKOUTS_EXISTS"
                
                if [ "$USERS_EXISTS" = "1" ] && [ "$WORKOUTS_EXISTS" = "1" ]; then
                    echo "✅ Both tables created successfully!"
                else
                    echo "❌ Tables missing"
                    exit 1
                fi
            else
                echo "❌ appdb database not found or empty"
                exit 1
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
                    
                    SUCCESS=0
                    TESTS=3
                    
                    for i in $(seq 1 $TESTS); do
                        echo "Test $i/$TESTS..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:8081/ > /dev/null 2>&1; then
                            SUCCESS=$((SUCCESS + 1))
                            echo "✓ Test passed"
                        else
                            echo "✗ Test failed"
                        fi
                        sleep 3
                    done
                    
                    echo "Tests passed: $SUCCESS/$TESTS"
                    
                    if [ "$SUCCESS" -ge 2 ]; then
                        echo "✅ Canary web application working"
                    else
                        echo "❌ Canary web application failing"
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
                    echo "=== Deploying to Production ==="
                    export DOCKER_HOST="tcp://192.168.0.1:2376"
                    
                    echo "1. Deploy/update production stack..."
                    
                    # Always deploy fresh stack (simpler)
                    docker stack rm app 2>/dev/null || true
                    sleep 10
                    
                    docker stack deploy -c docker-compose.yaml app --with-registry-auth
                    
                    echo "Waiting for production to start..."
                    sleep 60
                    
                    echo "2. Production services:"
                    docker service ls --filter name=app
                    
                    echo "3. Remove canary..."
                    docker stack rm app-canary 2>/dev/null || true
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
                    
                    for i in $(seq 1 3); do
                        echo "Test $i/3..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                            echo "✓ Test passed"
                        else
                            echo "✗ Test failed"
                            exit 1
                        fi
                        sleep 3
                    done
                    
                    echo "✅ Production deployment successful!"
                    '''
                }
            }
        }
    }
    
    post {
        always {
            echo "Cleaning up..."
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
                # Clean up canary on failure
                docker stack rm app-canary 2>/dev/null || true
                echo "Canary stack removed"
                '''
            }
        }
    }
}
