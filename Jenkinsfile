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
        sleep 10
        
        # Проверяем сервисы сразу
        echo "Проверка запущенных сервисов (через 10 сек):"
        docker service ls --filter name=${CANARY_APP_NAME}
        
        # Ждем еще и проверяем
        sleep 30
        
        echo "Проверка запущенных сервисов (через 40 сек):"
        docker service ls --filter name=${CANARY_APP_NAME}
        
        # Смотрим детальный статус MySQL
        echo "Детальный статус MySQL сервиса:"
        docker service ps ${CANARY_APP_NAME}_db --no-trunc || true
        
        # Проверяем логи MySQL если контейнер пытался запуститься
        echo "Логи MySQL сервиса:"
        docker service logs ${CANARY_APP_NAME}_db --tail 30 2>/dev/null || echo "Не удалось получить логи"
        
        # Проверяем контейнеры
        echo "Проверка всех контейнеров:"
        docker ps -a --filter "name=${CANARY_APP_NAME}" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
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
            
            # Ждем с проверкой готовности MySQL
            for i in {1..12}; do
              echo "Проверка MySQL... ($((i*5)) сек)"
              if docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword -e "SELECT 1" 2>/dev/null; then
                echo "✅ MySQL готов"
                break
              fi
              sleep 5
            done
            
            echo "2. Проверка базы данных appdb..."
            
            # Сначала проверяем существует ли база
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
                
                # Дополнительно показываем структуру таблиц
                echo "Структура таблиц:"
                docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword appdb -e "SHOW TABLES; DESCRIBE users; DESCRIBE workouts;" 2>/dev/null || true
                
              else
                echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Не все таблицы созданы!"
                
                # Диагностика
                echo "=== ДИАГНОСТИКА ==="
                echo "1. Логи MySQL:"
                docker service logs ${CANARY_APP_NAME}_db --tail 20 2>/dev/null || true
                
                echo "2. Проверка init.sql:"
                MYSQL_CONTAINER=$(docker ps -q --filter "name=${CANARY_APP_NAME}_db")
                if [ -n "$MYSQL_CONTAINER" ]; then
                  docker exec $MYSQL_CONTAINER ls -la /docker-entrypoint-initdb.d/ 2>/dev/null || true
                  docker exec $MYSQL_CONTAINER cat /docker-entrypoint-initdb.d/init.sql 2>/dev/null | head -20 || true
                fi
                
                exit 1
              fi
            else
              echo "❌ База данных appdb не существует!"
              
              # Проверяем какие базы есть
              echo "Доступные базы данных:"
              docker run --rm --network ${CANARY_APP_NAME}_canary-network mysql:8.0 mysql -h db -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null || true
              
              # Проверяем логи MySQL
              echo "Логи MySQL контейнера:"
              docker service logs ${CANARY_APP_NAME}_db --tail 30 2>/dev/null || true
              
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
            TESTS=10
            for i in $(seq 1 $TESTS); do
              echo "Тест $i/$TESTS..."
              if curl -f -s --max-time 15 http://${MANAGER_IP}:8081/ > /tmp/canary_$i.html 2>/dev/null; then
                if ! grep -iq "error\\|fatal\\|exception\\|failed" /tmp/canary_$i.html; then
                  SUCCESS=$((SUCCESS + 1))
                  echo "✓ Тест $i пройден"
                else
                  echo "✗ Тест $i: найдены ошибки в ответе"
                fi
              else
                echo "✗ Тест $i: нет ответа"
              fi
              sleep 4
            done
            echo "Успешных тестов: $SUCCESS/$TESTS"
            [ "$SUCCESS" -ge 8 ] || exit 1
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
              echo "Основной сервис существует — начинаем rolling update по одной реплике"

              # Шаг 1: Обновляем первую реплику продакшена
              echo "Шаг 1: Обновляем 1-ю реплику продакшена"
              docker service update \\
                --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} \\
                --update-parallelism 1 \\
                --update-delay 20s \\
                --detach=true \\
                ${APP_NAME}_web-server

              echo "Ожидание стабилизации после первой реплики..."
              sleep 40
              docker service ps ${APP_NAME}_web-server --no-trunc | head -20

              # Мониторинг после первого шага
              echo "=== Мониторинг после первой реплики ==="
              MONITOR_SUCCESS=0
              MONITOR_TESTS=10
              for j in $(seq 1 $MONITOR_TESTS); do
                if curl -f -s --max-time 15 http://${MANAGER_IP}:80/ > /tmp/monitor_$j.html 2>/dev/null; then
                  if ! grep -iq "error\\|fatal" /tmp/monitor_$j.html; then
                    MONITOR_SUCCESS=$((MONITOR_SUCCESS + 1))
                  fi
                fi
                sleep 5
              done
              echo "Успешных проверок после первой реплики: $MONITOR_SUCCESS/$MONITOR_TESTS"
              [ "$MONITOR_SUCCESS" -ge 9 ] || exit 1

              echo "Мониторинг после первой реплики прошёл!"
              sleep 60

              # Шаг 2: Обновляем оставшиеся реплики
              echo "Шаг 2: Обновляем оставшиеся реплики"
              docker service update \\
                --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} \\
                --update-parallelism 1 \\
                --update-delay 30s \\
                ${APP_NAME}_web-server

              echo "Ожидание завершения полного обновления..."
              sleep 90

              # Проверяем, что все реплики обновлены
              echo "Статус после обновления:"
              docker service ps ${APP_NAME}_web-server | head -20

              # Удаляем canary — он больше не нужен
              echo "Удаление canary stack..."
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
            
            for i in $(seq 1 5); do
              echo "Финальный тест $i/5..."
              if curl -f --max-time 10 http://${MANAGER_IP}:80/ > /dev/null 2>&1; then
                echo "✓ Тест $i пройден"
              else
                echo "✗ Тест $i не пройден"
                exit 1
              fi
              sleep 5
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
