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
    DOCKER_HOST = 'tcp://192.168.0.1:2376'
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
          sh """
            echo "=== СБОРКА DOCKER ОБРАЗОВ ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            echo "1. Сборка PHP образа (тег: \${BUILD_NUMBER})..."
            docker build -f php.Dockerfile . -t \${DOCKER_HUB_USER}/\${BACKEND_IMAGE_NAME}:\${BUILD_NUMBER}
            
            echo "2. Сборка MySQL образа (тег: \${BUILD_NUMBER})..."
            docker build -f mysql.Dockerfile . -t \${DOCKER_HUB_USER}/\${DATABASE_IMAGE_NAME}:\${BUILD_NUMBER}
            
            echo "✅ Образы собраны"
          """
        }
      }
    }

    stage('Push to Docker Hub') {
      steps {
        withCredentials([usernamePassword(credentialsId: 'docker-hub-credentials', usernameVariable: 'DOCKER_USER', passwordVariable: 'DOCKER_PASS')]) {
          script {
            sh '''
              echo "=== ОТПРАВКА ОБРАЗОВ В DOCKER HUB ==="
              export DOCKER_HOST="tcp://192.168.0.1:2376"
              
              echo "1. Логин в Docker Hub..."
              echo "${DOCKER_PASS}" | docker login -u "${DOCKER_USER}" --password-stdin
              
              echo "2. Публикация PHP образа с тегом '${BUILD_NUMBER}'..."
              docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
              
              echo "3. Публикация MySQL образа с тегом '${BUILD_NUMBER}'..."
              docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
              
              echo "✅ Образы успешно опубликованы"
            '''
          }
        }
      }
    }

    stage('Deploy Canary') {
      steps {
        script {
          sh '''
            echo "=== Развёртывание Canary (1 реплика) ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            # Удаляем старый стек если есть
            docker stack rm ${CANARY_APP_NAME} 2>/dev/null || true
            sleep 5
            
            # Разворачиваем новый стек
            docker stack deploy -c docker-compose_canary.yaml ${CANARY_APP_NAME} --with-registry-auth
            
            echo "Ожидание запуска сервисов..."
            sleep 60
            
            echo "Проверка запущенных сервисов:"
            docker service ls --filter name=${CANARY_APP_NAME}
            
            echo "Детальный статус MySQL:"
            docker service ps ${CANARY_APP_NAME}_db --no-trunc 2>/dev/null || true
          '''
        }
      }
    }

    stage('Canary Database Check') {
      steps {
        script {
          sh '''
            echo "=== ПРОВЕРКА ТАБЛИЦ БАЗЫ ДАННЫХ CANARY ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            echo "1. Ожидание инициализации MySQL..."
            
            # ИСПРАВЛЕННЫЙ ЦИКЛ - используем seq вместо {1..12}
            for i in $(seq 1 12); do
              echo "Проверка MySQL... $((i*5)) сек"
              if docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword -e "SELECT 1" 2>/dev/null; then
                echo "✅ MySQL готов"
                break
              fi
              sleep 5
            done
            
            echo "2. Проверка базы данных appdb..."
            
            # Проверяем существует ли база
            DB_EXISTS=$(docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'appdb';" 2>/dev/null || echo "0")
            echo "База данных appdb существует: $DB_EXISTS"
            
            if [ "$DB_EXISTS" = "1" ]; then
              # Проверяем таблицы
              USERS_EXISTS=$(docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword appdb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users';" 2>/dev/null || echo "0")
              WORKOUTS_EXISTS=$(docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword appdb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts';" 2>/dev/null || echo "0")
              
              echo "   Результат проверки:"
              echo "   • Таблица 'users': ${USERS_EXISTS} (1 = существует, 0 = нет)"
              echo "   • Таблица 'workouts': ${WORKOUTS_EXISTS} (1 = существует, 0 = нет)"
              
              # КРИТИЧЕСКАЯ ПРОВЕРКА
              if [ "${USERS_EXISTS}" = "1" ] && [ "${WORKOUTS_EXISTS}" = "1" ]; then
                echo "✅ ОБЕ таблицы существуют!"
                
                # Дополнительная проверка - показываем таблицы
                echo "Содержимое базы данных:"
                docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword appdb -e "SHOW TABLES; SELECT COUNT(*) as users_count FROM users; SELECT COUNT(*) as workouts_count FROM workouts;" 2>/dev/null || true
                
              else
                echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Не все таблицы созданы!"
                
                # Показываем какие таблицы есть
                echo "Существующие таблицы в appdb:"
                docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword appdb -e "SHOW TABLES;" 2>/dev/null || true
                
                exit 1
              fi
            else
              echo "❌ База данных appdb не существует!"
              
              # Проверяем какие базы есть
              echo "Все базы данных:"
              docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null || true
              
              exit 1
            fi
          '''
        }
      }
    }

    stage('Canary Testing') {
      steps {
        script {
          sh '''
            echo "=== Тестирование Canary-версии (порт 8081) ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            SUCCESS=0
            TESTS=5  # Уменьшим для скорости
            for i in $(seq 1 $TESTS); do
              echo "Тест $i/$TESTS..."
              if curl -f -s --max-time 10 http://${MANAGER_IP}:8081/ > /tmp/canary_$i.html 2>/dev/null; then
                if ! grep -iq "error\\|fatal\\|exception\\|failed" /tmp/canary_$i.html; then
                  SUCCESS=$((SUCCESS + 1))
                  echo "✓ Тест $i пройден"
                else
                  echo "✗ Тест $i: найдены ошибки в ответе"
                fi
              else
                echo "✗ Тест $i: нет ответа"
              fi
              sleep 2
            done
            echo "Успешных тестов: $SUCCESS/$TESTS"
            [ "$SUCCESS" -ge 3 ] || exit 1
            echo "Canary прошёл тестирование!"
          '''
        }
      }
    }

    stage('Gradual Traffic Shift') {
      steps {
        script {
          sh '''
            echo "=== Постепенное переключение трафика на новую версию ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            # Проверяем, существует ли основной сервис
            if docker service ls --filter name=${APP_NAME}_web-server | grep -q ${APP_NAME}_web-server; then
              echo "Основной сервис существует — начинаем rolling update"
              
              echo "1. Полное обновление продакшена"
              docker service update \\
                --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} \\
                --update-parallelism 1 \\
                --update-delay 30s \\
                ${APP_NAME}_web-server
              
              echo "Ожидание завершения обновления..."
              sleep 60
              
              echo "Статус после обновления:"
              docker service ps ${APP_NAME}_web-server --no-trunc | head -10
              
              echo "2. Удаление canary stack..."
              docker stack rm ${CANARY_APP_NAME} || true
              sleep 20
              
            else
              echo "Первый деплой — разворачиваем продакшен"
              docker stack deploy -c docker-compose.yaml ${APP_NAME} --with-registry-auth
              sleep 60
            fi
            
            echo "Постепенное переключение завершено"
          '''
        }
      }
    }

    stage('Final Verification') {
      steps {
        script {
          sh '''
            echo "=== Финальная проверка ==="
            export DOCKER_HOST="tcp://192.168.0.1:2376"
            
            for i in $(seq 1 3); do
              echo "Финальный тест $i/3..."
              if curl -f --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                echo "✓ Тест $i пройден"
              else
                echo "✗ Тест $i не пройден"
                exit 1
              fi
              sleep 3
            done
            echo "Все финальные тесты пройдены!"
          '''
        }
      }
    }
  }

  post {
    success {
      echo "✓ Canary-деплой успешно завершён!"
      sh '''
        export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
        docker logout
      '''
    }
    failure {
      echo "✗ Ошибка в пайплайне — canary удалён, продакшен остался прежним"
      sh '''
        export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
        docker stack rm ${CANARY_APP_NAME} || true
        echo "Canary удалён, продакшен не тронут"
        docker logout
      '''
    }
    always {
      sh '''
        export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
        docker image prune -f || true
      '''
    }
  }
}
