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
        stage('Check Network') {
    steps {
        script {
            sh '''
            echo "=== CHECKING NETWORK ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            echo "1. List all networks:"
            docker network ls
            
            echo "2. Check canary network:"
            docker network inspect app-canary_canary-network 2>/dev/null || echo "Network not found"
            
            echo "3. Check if containers are in network:"
            docker ps --filter "name=app-canary" --format "table {{.Names}}\\t{{.Networks}}"
            
            echo "4. Inspect MySQL container network:"
            MYSQL_CONTAINER=$(docker ps -q --filter "name=app-canary_db")
            if [ -n "$MYSQL_CONTAINER" ]; then
                docker inspect $MYSQL_CONTAINER --format='{{range $net, $settings := .NetworkSettings.Networks}}{{$net}}: {{$settings.IPAddress}}{{"\\n"}}{{end}}'
            fi
            '''
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
            
            # Удаляем старый стек
            docker stack rm app-canary 2>/dev/null || true
            sleep 5
            
            # Создаем сеть заранее если нужно
            echo "Creating network if not exists..."
            docker network create -d overlay --attachable app-canary_network 2>/dev/null || true
            
            echo "Network status:"
            docker network ls | grep canary || true
            
            # Деплоим
            docker stack deploy -c docker-compose_canary.yaml app-canary --with-registry-auth
            
            echo "Waiting 30 seconds..."
            sleep 30
            
            echo "Service status:"
            docker service ls --filter name=app-canary
            
            echo "Network inspection:"
            docker network inspect app-canary_canary-network 2>/dev/null || docker network inspect app-canary_network 2>/dev/null || echo "Cannot inspect network"
            '''
        }
    }
}
        
        
        stage('Check Database') {
            steps {
                script {
                    sh """
                    echo "=== Checking Database ==="
                    export DOCKER_HOST=tcp://192.168.0.1:2376
                    
                    echo "1. Waiting for MySQL to be ready..."
                    
                    # Wait for MySQL with timeout
                    for i in \$(seq 1 10); do
                        echo "Attempt \$i/10..."
                        if docker run --rm --network app-canary_canary-network mysql:8.0 \\
                           mysql -h db -u root -prootpassword -e "SELECT 1" 2>/dev/null; then
                            echo "✅ MySQL is ready"
                            break
                        fi
                        sleep 10
                    done
                    
                    echo "2. Checking databases..."
                    docker run --rm --network app-canary_canary-network mysql:8.0 \\
                        mysql -h db -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null || echo "Failed to connect"
                    
                    echo "3. Checking appdb database..."
                    if docker run --rm --network app-canary_canary-network mysql:8.0 \\
                       mysql -h db -u root -prootpassword -e "USE appdb; SHOW TABLES;" 2>/dev/null; then
                        echo "✅ appdb database exists"
                        
                        # Check tables
                        USERS_TABLE=\$(docker run --rm --network app-canary_canary-network mysql:8.0 \\
                            mysql -h db -u root -prootpassword appdb -N -e \\
                            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='appdb' AND table_name='users';" 2>/dev/null || echo "0")
                        
                        WORKOUTS_TABLE=\$(docker run --rm --network app-canary_canary-network mysql:8.0 \\
                            mysql -h db -u root -prootpassword appdb -N -e \\
                            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='appdb' AND table_name='workouts';" 2>/dev/null || echo "0")
                        
                        echo "Users table exists: \$USERS_TABLE"
                        echo "Workouts table exists: \$WORKOUTS_TABLE"
                        
                        if [ "\$USERS_TABLE" = "1" ] && [ "\$WORKOUTS_TABLE" = "1" ]; then
                            echo "✅ Both tables created successfully!"
                            
                            # Show table structure
                            echo "Table structure:"
                            docker run --rm --network app-canary_canary-network mysql:8.0 \\
                                mysql -h db -u root -prootpassword appdb -e "DESCRIBE users; DESCRIBE workouts;" 2>/dev/null || true
                        else
                            echo "❌ Tables not created properly"
                            exit 1
                        fi
                    else
                        echo "❌ appdb database not found or not accessible"
                        exit 1
                    fi
                    """
                }
            }
        }
        
        stage('Test Canary') {
            steps {
                script {
                    sh """
                    echo "=== Testing Canary ==="
                    export DOCKER_HOST=tcp://192.168.0.1:2376
                    
                    echo "Testing PHP application on port 8081..."
                    
                    SUCCESS=0
                    TOTAL_TESTS=5
                    
                    for i in \$(seq 1 \$TOTAL_TESTS); do
                        echo "Test \$i/\$TOTAL_TESTS..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:8081/ > /dev/null 2>&1; then
                            SUCCESS=\$((SUCCESS + 1))
                            echo "✓ Test passed"
                        else
                            echo "✗ Test failed"
                        fi
                        sleep 2
                    done
                    
                    echo "Tests passed: \$SUCCESS/\$TOTAL_TESTS"
                    
                    if [ "\$SUCCESS" -ge 3 ]; then
                        echo "✅ Canary tests passed"
                    else
                        echo "❌ Canary tests failed"
                        exit 1
                    fi
                    """
                }
            }
        }
        
        stage('Deploy to Production') {
    steps {
        script {
            sh '''
            echo "=== Deploying Production Stack ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            # Удаляем старый стек если есть
            docker stack rm app 2>/dev/null || true
            sleep 10
            
            # Разворачиваем новый стек
            echo "Deploying new production stack..."
            docker stack deploy -c docker-compose.yaml app --with-registry-auth
            
            echo "Waiting for production to start..."
            sleep 90
            
            echo "Production services:"
            docker service ls --filter name=app
            
            # Удаляем canary
            echo "Removing canary stack..."
            docker stack rm app-canary 2>/dev/null || true
            '''
        }
    }
}
        
        stage('Final Verification') {
            steps {
                script {
                    sh """
                    echo "=== Final Verification ==="
                    export DOCKER_HOST=tcp://192.168.0.1:2376
                    
                    echo "Testing production on port 80..."
                    
                    for i in \$(seq 1 3); do
                        echo "Test \$i/3..."
                        if curl -f -s --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                            echo "✓ Test passed"
                        else
                            echo "✗ Test failed"
                            exit 1
                        fi
                        sleep 3
                    done
                    
                    echo "✅ All tests passed!"
                    """
                }
            }
        }
    }
    
    post {
        always {
            echo "Cleaning up..."
            script {
                sh """
                export DOCKER_HOST=tcp://192.168.0.1:2376 2>/dev/null || true
                docker logout 2>/dev/null || true
                docker image prune -f 2>/dev/null || true
                """
            }
        }
        success {
            echo "✅ Pipeline completed successfully!"
        }
        failure {
            echo "❌ Pipeline failed"
            script {
                sh """
                export DOCKER_HOST=tcp://192.168.0.1:2376 2>/dev/null || true
                # Remove canary on failure
                docker stack rm app-canary 2>/dev/null || true
                echo "Canary removed, production untouched"
                """
            }
        }
    }
}
