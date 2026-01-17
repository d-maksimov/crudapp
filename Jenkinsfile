pipeline {
  agent { label 'docker-agent' }
  
  environment {
    APP_NAME = 'app'
    CANARY_APP_NAME = 'app-canary'
    DOCKER_HUB_USER = 'danil221'
    GIT_REPO = 'https://github.com/d-maksimov/crudapp.git'
    BACKEND_IMAGE_NAME = 'php-app'
    DATABASE_IMAGE_NAME = 'mysql-app'
    MANAGER_IP = '192.168.0.1'
  }

  stages {
    stage('Checkout') {
      steps {
        git url: "${GIT_REPO}", branch: 'main'
      }
    }

    stage('Build Docker Images') {
      steps {
        script {
          // Простой PHP Dockerfile чтобы избежать проблем с сетью
          writeFile file: 'simple.php.Dockerfile', text: '''
FROM php:8.1-apache
RUN docker-php-ext-install pdo_mysql
COPY ./app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
'''
          sh "docker build -f simple.php.Dockerfile . -t ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}"
          sh "docker build -f mysql.Dockerfile . -t ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}"
        }
      }
    }

    stage('Push to Docker Hub') {
      steps {
        withCredentials([usernamePassword(credentialsId: 'docker-hub-credentials', usernameVariable: 'DOCKER_USER', passwordVariable: 'DOCKER_PASS')]) {
          sh """
            echo \$DOCKER_PASS | docker login -u \$DOCKER_USER --password-stdin
            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
            
            # Также пушим как latest
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
        sh """
          echo "=== Развёртывание Canary (1 реплика) ==="
          
          # Создаем временный файл с подставленным BUILD_NUMBER
          sed "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary.yaml > docker-compose_canary_temp.yaml
          
          docker stack deploy -c docker-compose_canary_temp.yaml ${CANARY_APP_NAME} --with-registry-auth
          
          # Ждем запуска
          echo "Ожидание запуска canary сервисов..."
          for i in \$(seq 1 30); do
            sleep 5
            echo "Проверка \$i..."
            docker service ls --filter name=${CANARY_APP_NAME}
          done
          
          # Дополнительное время для БД
          sleep 20
        """
      }
    }

    stage('Check Database Tables') {
      steps {
        sh """
          echo "=== Проверка таблиц в базе данных ==="
          
          # Проверяем что таблицы users и workouts существуют
          cat > /tmp/check_tables.sql << 'SQL'
SELECT 
  IF(EXISTS(SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'appdb' AND table_name = 'users'), 
     'OK', 'MISSING') as users_table,
  IF(EXISTS(SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'appdb' AND table_name = 'workouts'), 
     'OK', 'MISSING') as workouts_table;
SQL

          # Получаем имя контейнера БД canary
          DB_CONTAINER=\$(docker ps --filter "name=${CANARY_APP_NAME}_db" --format "{{.Names}}" | head -1)
          
          if [ -n "\$DB_CONTAINER" ]; then
            echo "Проверка таблиц в контейнере: \$DB_CONTAINER"
            RESULT=\$(docker exec \$DB_CONTAINER mysql -u user -puserpassword appdb -e "source /tmp/check_tables.sql" --batch --silent 2>/dev/null || echo "ERROR")
            echo "Результат проверки: \$RESULT"
            
            if echo "\$RESULT" | grep -q "MISSING"; then
              echo "✗ Отсутствуют некоторые таблицы в БД!"
              echo "Детали: \$RESULT"
              exit 1
            else
              echo "✓ Все таблицы присутствуют в БД"
            fi
          else
            echo "⚠ Контейнер БД не найден, пропускаем проверку таблиц"
          fi
        """
      }
    }

    stage('Canary Testing') {
      steps {
        sh """
          echo "=== Тестирование Canary-версии (порт 8081) ==="
          
          SUCCESS=0
          TESTS=10
          
          for i in \$(seq 1 \$TESTS); do
            echo "Тест \$i/\$TESTS..."
            
            if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /tmp/canary_\$i.html; then
              if ! grep -iq "error\\\\|fatal\\\\|exception\\\\|failed" /tmp/canary_\$i.html; then
                SUCCESS=\$((SUCCESS + 1))
                echo "✓ Тест \$i пройден"
              else
                echo "✗ Тест \$i: найдены ошибки в ответе"
                cat /tmp/canary_\$i.html | head -20
              fi
            else
              echo "✗ Тест \$i: нет ответа"
            fi
            
            sleep 4
          done
          
          echo "Успешных тестов: \$SUCCESS/\$TESTS"
          
          if [ "\$SUCCESS" -ge 8 ]; then
            echo "✓ Canary прошёл тестирование!"
          else
            echo "✗ Canary не прошёл тестирование"
            exit 1
          fi
        """
      }
    }

    stage('Deploy to Production') {
      when {
        expression { currentBuild.resultIsBetterOrEqualTo('SUCCESS') }
      }
      steps {
        sh """
          echo "=== Развертывание Production ==="
          
          # Проверяем существует ли основной стек
          if docker stack ls | grep -q ${APP_NAME}; then
            echo "Обновляем существующий production..."
            
            # Обновляем только web сервис (БД оставляем как есть)
            docker service update \\
              --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest \\
              ${APP_NAME}_web
            
            echo "Ожидание обновления..."
            sleep 30
            
            # Проверяем обновление
            echo "Статус после обновления:"
            docker service ps ${APP_NAME}_web | head -10
            
          else
            echo "Первый деплой production..."
            docker stack deploy -c docker-compose.yaml ${APP_NAME} --with-registry-auth
            sleep 60
          fi
          
          echo "Production развернут!"
        """
      }
    }

    stage('Final Verification') {
      steps {
        sh """
          echo "=== Финальная проверка ==="
          
          for i in \$(seq 1 5); do
            echo "Финальный тест \$i/5..."
            
            if curl -f --max-time 10 http://${MANAGER_IP}:8080/ > /dev/null 2>&1; then
              echo "✓ Тест \$i пройден"
            else
              echo "✗ Тест \$i не пройден"
              exit 1
            fi
            
            sleep 5
          done
          
          echo "✓ Все финальные тесты пройдены!"
        """
      }
    }

    stage('Cleanup Canary') {
      when {
        expression { currentBuild.resultIsBetterOrEqualTo('SUCCESS') }
      }
      steps {
        sh """
          echo "Очистка canary развертывания..."
          docker stack rm ${CANARY_APP_NAME} || true
          sleep 10
          echo "✓ Canary удален"
        """
      }
    }
  }

  post {
    success {
      echo "✓ Canary-деплой успешно завершён!"
      sh 'docker logout || true'
    }
    failure {
      echo "✗ Ошибка в пайплайне"
      sh """
        docker stack rm ${CANARY_APP_NAME} || true
        echo "Canary удалён при ошибке"
      """
      sh 'docker logout || true'
    }
    always {
      sh 'docker image prune -f || true'
      sh 'rm -f docker-compose_canary_temp.yaml /tmp/canary_*.html /tmp/check_tables.sql || true'
    }
  }
}
