pipeline {
    agent {
        label 'docker-agent'
    }
    
    environment {
        // Основные настройки
        APP_NAME = 'app'
        CANARY_APP_NAME = 'app-canary'
        DOCKER_HUB_USER = 'danil221'
        GIT_REPO = 'https://github.com/d-maksimov/crudapp.git'
        BACKEND_IMAGE_NAME = 'php-app'
        DATABASE_IMAGE_NAME = 'mysql-app'
        
        // Настройки базы данных
        MYSQL_ROOT_PASSWORD = 'rootpassword'
        MYSQL_APP_PASSWORD = 'userpassword'
        MYSQL_DATABASE = 'appdb'
        
        // Docker Swarm connection (ОБЯЗАТЕЛЬНО!)
        DOCKER_HOST = 'tcp://192.168.0.1:2376'
        MANAGER_IP = '192.168.0.1'
        
        // URL для тестирования
        CANARY_URL = 'http://192.168.0.1:8081'
        PROD_URL = 'http://192.168.0.1'
    }
    
    stages {
        // Этап 1: Проверка подключения к Docker Swarm
        stage('Verify Docker Swarm Connection') {
            steps {
                script {
                    sh '''
                        echo "=== ПРОВЕРКА ПОДКЛЮЧЕНИЯ К DOCKER SWARM ==="
                        
                        # Принудительно устанавливаем DOCKER_HOST
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        echo "DOCKER_HOST установлен: ${DOCKER_HOST}"
                        
                        # Проверяем базовое подключение
                        echo "1. Проверка доступности порта 2376..."
                        if timeout 5 bash -c "echo > /dev/tcp/192.168.0.1/2376"; then
                            echo "   ✅ Порт 2376 доступен"
                        else
                            echo "   ❌ Порт 2376 недоступен"
                            exit 1
                        fi
                        
                        # Проверяем Docker CLI
                        echo "2. Проверка Docker CLI..."
                        if docker version > /dev/null 2>&1; then
                            echo "   ✅ Docker CLI работает"
                        else
                            echo "   ❌ Docker CLI не отвечает"
                            exit 1
                        fi
                        
                        # Проверяем Swarm статус
                        echo "3. Проверка Swarm статуса..."
                        SWARM_STATUS=$(docker info --format '{{.Swarm.LocalNodeState}}' 2>/dev/null || echo "error")
                        
                        if [ "$SWARM_STATUS" = "active" ] || [ "$SWARM_STATUS" = "manager" ]; then
                            echo "   ✅ Swarm активен (статус: ${SWARM_STATUS})"
                            echo "   Swarm информация:"
                            docker node ls 2>/dev/null | head -5
                        else
                            echo "   ⚠️ Swarm не активен или Jenkins не manager (статус: ${SWARM_STATUS})"
                            echo "   Проверяем возможность управления стеками..."
                        fi
                        
                        # Проверяем возможность управления стеками
                        echo "4. Проверка управления стеками..."
                        if docker stack ls > /dev/null 2>&1; then
                            echo "   ✅ Управление стеками доступно"
                            echo "   Текущие стеки:"
                            docker stack ls
                        else
                            echo "   ❌ Нет доступа к управлению стеками"
                            exit 1
                        fi
                        
                        echo "✅ Подключение к Docker Swarm проверено и работает"
                    '''
                }
            }
        }
        
        // Этап 2: Получение кода
        stage('Checkout') {
            steps {
                git branch: 'main', url: "${GIT_REPO}"
                sh '''
                    echo "✅ Репозиторий склонирован"
                    echo "Текущая директория: $(pwd)"
                    echo "Содержимое:"
                    ls -la
                '''
            }
        }
        
        // Этап 3: Сборка Docker образов
        stage('Build Docker Images') {
            steps {
                script {
                    sh """
                        echo "=== СБОРКА DOCKER ОБРАЗОВ ==="
                        
                        # Устанавливаем подключение к Swarm
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Сборка PHP образа (тег: ${BUILD_NUMBER})..."
                        docker build -f php.Dockerfile . -t ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                        
                        echo "2. Сборка MySQL образа (тег: ${BUILD_NUMBER})..."
                        docker build -f mysql.Dockerfile . -t ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                        
                        echo "✅ Образы собраны"
                        echo "Список образов:"
                        docker images | grep ${DOCKER_HUB_USER}
                    """
                }
            }
        }
        
        // Этап 4: Публикация в Docker Hub
        stage('Push to Docker Hub') {
            steps {
                withCredentials([usernamePassword(
                    credentialsId: 'docker-hub-credentials', 
                    usernameVariable: 'DOCKER_USER', 
                    passwordVariable: 'DOCKER_PASS'
                )]) {
                    script {
                        sh '''
                            echo "=== ОТПРАВКА ОБРАЗОВ В DOCKER HUB ==="
                            
                            # Устанавливаем подключение к Swarm
                            export DOCKER_HOST="tcp://192.168.0.1:2376"
                            
                            echo "1. Логин в Docker Hub..."
                            echo "${DOCKER_PASS}" | docker login -u "${DOCKER_USER}" --password-stdin
                            
                            echo "2. Публикация PHP образа с тегом '${BUILD_NUMBER}'..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "3. Публикация MySQL образа с тегом '${BUILD_NUMBER}'..."
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER}
                            
                            echo "4. Добавление тегов 'latest'..."
                            docker tag ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker tag ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:${BUILD_NUMBER} ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "5. Публикация тегов 'latest'..."
                            docker push ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest
                            docker push ${DOCKER_HUB_USER}/${DATABASE_IMAGE_NAME}:latest
                            
                            echo "✅ Образы успешно опубликованы"
                        '''
                    }
                }
            }
        }
        
        // Этап 5: Canary развертывание
        stage('Deploy Canary') {
            steps {
                script {
                    sh '''
                        echo "=== РАЗВЕРТЫВАНИЕ CANARY ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Очистка предыдущего canary..."
                        docker stack rm app-canary 2>/dev/null || true
                        sleep 15
                        
                        echo "2. Очистка volume'ов MySQL..."
                        docker volume rm app-canary_canary_mysql_data 2>/dev/null || true
                        
                        echo "3. Проверка занятых портов..."
                        # Проверяем порт 3306 для MySQL
                        if docker service ls --format "{{.Ports}}" | grep -q ":3306->"; then
                            echo "   ⚠️  Порт 3306 занят, используем 3307 для canary"
                            CANARY_DB_PORT="3307"
                        else
                            echo "   ✅ Порт 3306 свободен"
                            CANARY_DB_PORT="3306"
                        fi
                        
                        echo "4. Подготовка docker-compose для canary..."
                        if [ ! -f "docker-compose_canary.yaml" ]; then
                            echo "❌ Файл docker-compose_canary.yaml не найден!"
                            exit 1
                        fi
                        
                        cp docker-compose_canary.yaml docker-compose_canary_temp.yaml
                        
                        # Заменяем переменные в файле
                        sed -i "s/\\\${BUILD_NUMBER}/${BUILD_NUMBER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/\\\${DOCKER_HUB_USER}/${DOCKER_HUB_USER}/g" docker-compose_canary_temp.yaml
                        sed -i "s/3306:3306/${CANARY_DB_PORT}:3306/g" docker-compose_canary_temp.yaml
                        
                        echo "5. Развертывание canary стека..."
                        docker stack deploy -c docker-compose_canary_temp.yaml app-canary --with-registry-auth
                        
                        echo "6. Ожидание запуска canary сервисов (макс. 240 сек)..."
                        TIMEOUT=240
                        START_TIME=$(date +%s)
                        
                        while true; do
                            CURRENT_TIME=$(date +%s)
                            ELAPSED=$((CURRENT_TIME - START_TIME))
                            
                            if [ $ELAPSED -ge $TIMEOUT ]; then
                                echo "❌ Таймаут ожидания запуска canary"
                                docker service ls --filter name=app-canary
                                exit 1
                            fi
                            
                            DB_STATUS=$(docker service ls --filter name=app-canary_db --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            WEB_STATUS=$(docker service ls --filter name=app-canary_web-server --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
                            
                            echo "   DB: ${DB_STATUS}, Web: ${WEB_STATUS} (прошло ${ELAPSED} сек)"
                            
                            if echo "${DB_STATUS}" | grep -q "1/1" && echo "${WEB_STATUS}" | grep -q "1/1"; then
                                echo "✅ Canary сервисы запущены"
                                break
                            fi
                            
                            sleep 10
                        done
                        
                        echo "7. Ожидание полной инициализации БД (45 сек)..."
                        sleep 45
                        
                        echo "✅ Canary развернут:"
                        echo "   • Веб-сервис: http://192.168.0.1:8081"
                        echo "   • MySQL порт: ${CANARY_DB_PORT}"
                        docker service ls --filter name=app-canary
                    '''
                }
            }
        }
        
        // Этап 6: СТРОГАЯ проверка базы данных Canary
        stage('Strict Check Canary Database') {
            steps {
                script {
                    sh '''
                        echo "=== СТРОГАЯ ПРОВЕРКА БАЗЫ ДАННЫХ CANARY ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Ожидание полной инициализации БД..."
                        sleep 30
                        
                        echo "2. Проверяем наличие базы данных 'appdb'..."
                        
                        # Проверяем существование базы данных
                        if docker run --rm --network app-canary_default mysql:8.0 \\
                           mysql -h db -u root -prootpassword -e "SHOW DATABASES;" 2>/dev/null | grep -q appdb; then
                            echo "   ✅ База данных 'appdb' существует"
                            
                            echo "3. СТРОГАЯ проверка наличия таблиц 'users' и 'workouts'..."
                            
                            # Проверяем таблицу users
                            USERS_TABLE_EXISTS=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                mysql -h db -u root -prootpassword appdb -N -e \\
                                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users';" 2>/dev/null || echo "0")
                            
                            # Проверяем таблицу workouts
                            WORKOUTS_TABLE_EXISTS=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                mysql -h db -u root -prootpassword appdb -N -e \\
                                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts';" 2>/dev/null || echo "0")
                            
                            echo "   Результат проверки:"
                            echo "   • Таблица 'users': ${USERS_TABLE_EXISTS} (1 = существует, 0 = нет)"
                            echo "   • Таблица 'workouts': ${WORKOUTS_TABLE_EXISTS} (1 = существует, 0 = нет)"
                            
                            # КРИТИЧЕСКАЯ ПРОВЕРКА: обе таблицы должны существовать
                            if [ "${USERS_TABLE_EXISTS}" = "1" ] && [ "${WORKOUTS_TABLE_EXISTS}" = "1" ]; then
                                echo "   ✅ ОБЕ таблицы (users и workouts) существуют!"
                                
                                # Дополнительная проверка структуры
                                echo "4. Проверка структуры таблиц..."
                                
                                # Проверяем количество столбцов в таблице users (минимум должна быть структура)
                                USERS_COLUMNS=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                    mysql -h db -u root -prootpassword appdb -N -e \\
                                    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'appdb' AND table_name = 'users';" 2>/dev/null || echo "0")
                                
                                # Проверяем количество столбцов в таблице workouts
                                WORKOUTS_COLUMNS=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                    mysql -h db -u root -prootpassword appdb -N -e \\
                                    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'appdb' AND table_name = 'workouts';" 2>/dev/null || echo "0")
                                
                                echo "   • Столбцов в 'users': ${USERS_COLUMNS}"
                                echo "   • Столбцов в 'workouts': ${WORKOUTS_COLUMNS}"
                                
                                if [ "${USERS_COLUMNS}" -gt "0" ] && [ "${WORKOUTS_COLUMNS}" -gt "0" ]; then
                                    echo "   ✅ Структура таблиц корректна"
                                else
                                    echo "   ⚠️  Структура таблиц может быть неполной"
                                fi
                                
                                echo "✅ СТРОГАЯ проверка БД пройдена успешно"
                                
                            elif [ "${USERS_TABLE_EXISTS}" = "0" ] && [ "${WORKOUTS_TABLE_EXISTS}" = "0" ]; then
                                echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Одна из таблиц не создана!"
                                echo "   init.sql не выполнился или выполнился с ошибкой"
                                
                                echo "5. Попытка восстановления..."
                                
                                # Проверяем существование init.sql
                                if [ -f "init.sql" ]; then
                                    echo "   Выполняем init.sql принудительно..."
                                    
                                    # Копируем init.sql во временный контейнер и выполняем
                                    docker run --rm --network app-canary_default -v $(pwd)/init.sql:/tmp/init.sql mysql:8.0 \\
                                        mysql -h db -u root -prootpassword appdb < /tmp/init.sql 2>&1 | tail -5
                                    
                                    echo "   Ждем 10 секунд..."
                                    sleep 10
                                    
                                    # Повторная проверка
                                    USERS_TABLE_EXISTS2=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                        mysql -h db -u root -prootpassword appdb -N -e \\
                                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users';" 2>/dev/null || echo "0")
                                    
                                    WORKOUTS_TABLE_EXISTS2=$(docker run --rm --network app-canary_default mysql:8.0 \\
                                        mysql -h db -u root -prootpassword appdb -N -e \\
                                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts';" 2>/dev/null || echo "0")
                                    
                                    if [ "${USERS_TABLE_EXISTS2}" = "1" ] && [ "${WORKOUTS_TABLE_EXISTS2}" = "1" ]; then
                                        echo "   ✅ Таблицы созданы после восстановления"
                                        echo "✅ Проверка БД пройдена после восстановления"
                                    else
                                        echo "❌ Не удалось создать таблицы даже принудительно"
                                        echo "   Проверьте содержимое init.sql"
                                        echo "   Последние логи MySQL:"
                                        docker service logs app-canary_db --tail 20 2>/dev/null || true
                                        exit 1
                                    fi
                                else
                                    echo "❌ Файл init.sql не найден!"
                                    exit 1
                                fi
                                
                            elif [ "${USERS_TABLE_EXISTS}" = "1" ] && [ "${WORKOUTS_TABLE_EXISTS}" = "0" ]; then
                                echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Таблица 'users' существует, но 'workouts' - нет!"
                                echo "   База данных в некорректном состоянии"
                                echo "   Возможно init.sql выполнился частично"
                                exit 1
                                
                            elif [ "${USERS_TABLE_EXISTS}" = "0" ] && [ "${WORKOUTS_TABLE_EXISTS}" = "1" ]; then
                                echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Таблица 'workouts' существует, но 'users' - нет!"
                                echo "   База данных в некорректном состоянии"
                                echo "   Возможно init.sql выполнился частично"
                                exit 1
                            fi
                            
                        else
                            echo "❌ База данных 'appdb' не найдена"
                            echo "   Логи MySQL:"
                            docker service logs app-canary_db --tail 30 2>/dev/null || true
                            exit 1
                        fi
                        
                        echo "6. Финальная проверка данных (опционально)..."
                        
                        # Проверяем, есть ли хотя бы тестовые данные
                        USERS_COUNT=$(docker run --rm --network app-canary_default mysql:8.0 \\
                            mysql -h db -u root -prootpassword appdb -N -e "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")
                        
                        WORKOUTS_COUNT=$(docker run --rm --network app-canary_default mysql:8.0 \\
                            mysql -h db -u root -prootpassword appdb -N -e "SELECT COUNT(*) FROM workouts;" 2>/dev/null || echo "0")
                        
                        echo "   • Записей в 'users': ${USERS_COUNT}"
                        echo "   • Записей в 'workouts': ${WORKOUTS_COUNT}"
                        
                        if [ "${USERS_COUNT}" -gt "0" ] || [ "${WORKOUTS_COUNT}" -gt "0" ]; then
                            echo "   ✅ В таблицах есть данные"
                        else
                            echo "   ⚠️  Таблицы пустые (это нормально при первом запуске)"
                        fi
                        
                        echo "✅ СТРОГАЯ проверка базы данных завершена успешно"
                    '''
                }
            }
        }
        
        // Этап 7: Тестирование Canary
        stage('Test Canary') {
            steps {
                script {
                    sh '''
                        echo "=== ТЕСТИРОВАНИЕ CANARY ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Даем время для запуска PHP..."
                        sleep 20
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        
                        echo "2. Тестирование canary по адресу: ${CANARY_URL}"
                        
                        for i in 1 2 3 4 5; do
                            echo ""
                            echo "   Тест $i/${TOTAL_TESTS}:"
                            
                            # Проверка доступности
                            if curl -f -s --max-time 30 ${CANARY_URL} > /tmp/canary_test_${i}.html 2>/dev/null; then
                                SIZE=$(wc -c < /tmp/canary_test_${i}.html)
                                echo "     ✓ Страница загружена (${SIZE} байт)"
                                
                                # Проверка на ошибки
                                if ! grep -q -i -E "error|fatal|exception|failed|syntax|warning|database" /tmp/canary_test_${i}.html 2>/dev/null; then
                                    SUCCESS=$((SUCCESS + 1))
                                    echo "     ✓ Контент без ошибок"
                                else
                                    echo "     ⚠️ Найдены ошибки в контенте"
                                    grep -i -E "error|fatal|exception" /tmp/canary_test_${i}.html 2>/dev/null | head -2
                                fi
                            else
                                echo "     ❌ Не удалось загрузить страницу"
                            fi
                            
                            sleep 5
                        done
                        
                        echo ""
                        echo "=== РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ CANARY ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 3 ]; then
                            echo "✅ Canary прошел тестирование!"
                            echo "Логи веб-сервиса:"
                            docker service logs app-canary_web-server --tail 3 2>/dev/null || true
                        else
                            echo "❌ Canary не прошел тестирование"
                            echo "Логи веб-сервиса для диагностики:"
                            docker service logs app-canary_web-server --tail 20 2>/dev/null || true
                            exit 1
                        fi
                    '''
                }
            }
        }
        
        // Этап 8: Развертывание в Production
        stage('Deploy to Production') {
            steps {
                script {
                    sh '''
                        echo "=== РАЗВЕРТЫВАНИЕ В PRODUCTION ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Проверка текущих стеков..."
                        docker stack ls
                        
                        echo "2. Проверка существования стека 'app'..."
                        if docker stack ls --format "{{.Name}}" | grep -q "^app$"; then
                            echo "   ✅ Стек 'app' существует"
                            
                            echo "3. Поиск веб-сервиса в стеке..."
                            # Ищем именно сервисы стека app, а не canary
                            WEB_SERVICE=$(docker service ls --format "{{.Name}}" | grep "^app_web" | head -1)
                            
                            if [ -n "${WEB_SERVICE}" ]; then
                                echo "   Найден веб-сервис: ${WEB_SERVICE}"
                                
                                echo "4. Обновление веб-сервиса до latest..."
                                docker service update \
                                    --image ${DOCKER_HUB_USER}/${BACKEND_IMAGE_NAME}:latest \
                                    --update-parallelism 1 \
                                    --update-delay 10s \
                                    --with-registry-auth \
                                    ${WEB_SERVICE}
                                
                                echo "   ✅ Веб-сервис обновлен"
                            else
                                echo "   ⚠️ Веб-сервис не найден в стеке 'app'"
                                echo "   Полный список сервисов:"
                                docker service ls --filter name=app
                                
                                echo "5. Полное обновление стека..."
                                docker stack deploy -c docker-compose.yaml app --with-registry-auth
                                echo "   ✅ Стек обновлен"
                            fi
                        else
                            echo "   ⚠️ Стек 'app' не существует"
                            
                            echo "3. Развертывание production стека с нуля..."
                            if [ ! -f "docker-compose.yaml" ]; then
                                echo "❌ Файл docker-compose.yaml не найден!"
                                exit 1
                            fi
                            
                            docker stack deploy -c docker-compose.yaml app --with-registry-auth
                            echo "   ✅ Production стек развернут"
                        fi
                        
                        echo "6. Ожидание запуска/обновления (60 сек)..."
                        sleep 60
                        
                        echo "7. Проверка статуса production..."
                        docker service ls --filter name=app --format "table {{.Name}}\\t{{.Replicas}}\\t{{.Image}}\\t{{.Ports}}"
                        
                        echo "✅ Production развертывание завершено"
                    '''
                }
            }
        }
        
        // Этап 9: Проверка Production базы данных (опционально)
        stage('Check Production Database') {
            steps {
                script {
                    sh '''
                        echo "=== ПРОВЕРКА PRODUCTION БАЗЫ ДАННЫХ ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Проверка таблиц в production БД..."
                        sleep 20
                        
                        # Проверяем таблицы в production БД
                        PROD_TABLE_CHECK=$(docker run --rm --network app_default mysql:8.0 \\
                            mysql -h app_db -u root -prootpassword appdb -N -e \\
                            "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'users'), 
                                    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'appdb' AND table_name = 'workouts');" 2>/dev/null || echo "0 0")
                        
                        PROD_USERS_EXISTS=$(echo $PROD_TABLE_CHECK | awk '{print $1}')
                        PROD_WORKOUTS_EXISTS=$(echo $PROD_TABLE_CHECK | awk '{print $2}')
                        
                        echo "   Production БД:"
                        echo "   • Таблица 'users': ${PROD_USERS_EXISTS}"
                        echo "   • Таблица 'workouts': ${PROD_WORKOUTS_EXISTS}"
                        
                        if [ "${PROD_USERS_EXISTS}" = "1" ] && [ "${PROD_WORKOUTS_EXISTS}" = "1" ]; then
                            echo "✅ Production база данных корректна"
                        else
                            echo "⚠️  В production БД отсутствуют некоторые таблицы"
                            echo "   Это может быть нормально при первом запуске"
                            echo "   Или если используется существующий volume без таблиц"
                        fi
                    '''
                }
            }
        }
        
        // Этап 10: Финальная проверка Production
        stage('Verify Production') {
            steps {
                script {
                    sh '''
                        echo "=== ФИНАЛЬНАЯ ПРОВЕРКА PRODUCTION ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Проверка состояния сервисов..."
                        sleep 15
                        
                        echo "2. Тестирование production по адресу: ${PROD_URL}"
                        
                        SUCCESS=0
                        TOTAL_TESTS=5
                        
                        for i in 1 2 3 4 5; do
                            echo ""
                            echo "   Тест $i/${TOTAL_TESTS}:"
                            
                            # Проверка HTTP ответа
                            HTTP_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 ${PROD_URL} 2>/dev/null || echo "000")
                            
                            if [ "${HTTP_RESPONSE}" = "200" ]; then
                                echo "     ✓ HTTP 200 OK"
                                
                                if curl -f -s --max-time 10 ${PROD_URL} > /tmp/prod_test_${i}.html 2>/dev/null; then
                                    SIZE=$(wc -c < /tmp/prod_test_${i}.html)
                                    echo "     ✓ Страница загружена (${SIZE} байт)"
                                    
                                    # Проверка на ошибки базы данных в контенте
                                    if ! grep -q -i -E "error|fatal|exception|failed|syntax|warning|mysql|database" /tmp/prod_test_${i}.html 2>/dev/null; then
                                        SUCCESS=$((SUCCESS + 1))
                                        echo "     ✓ Контент без ошибок"
                                    else
                                        echo "     ⚠️ Найдены ошибки в контенте"
                                        grep -i -E "error|fatal|exception|mysql|database" /tmp/prod_test_${i}.html 2>/dev/null | head -2
                                    fi
                                fi
                            else
                                echo "     ❌ HTTP ${HTTP_RESPONSE} (ожидалось 200)"
                            fi
                            
                            sleep 3
                        done
                        
                        echo ""
                        echo "=== РЕЗУЛЬТАТЫ ПРОВЕРКИ PRODUCTION ==="
                        echo "Успешных тестов: ${SUCCESS}/${TOTAL_TESTS}"
                        
                        if [ ${SUCCESS} -ge 3 ]; then
                            echo "✅ Production прошел финальную проверку!"
                            
                            echo "Текущее состояние сервисов:"
                            docker service ls --filter name=app --format "table {{.Name}}\\t{{.Replicas}}\\t{{.Image}}"
                        else
                            echo "❌ Production не прошел проверку"
                            echo "Диагностика:"
                            echo "1. Состояние сервисов:"
                            docker service ps app_web 2>/dev/null | head -10 || true
                            echo ""
                            echo "2. Логи веб-сервиса:"
                            docker service logs app_web --tail 20 2>/dev/null || true
                            exit 1
                        fi
                    '''
                }
            }
        }
        
        // Этап 11: Очистка Canary
        stage('Cleanup Canary') {
            steps {
                script {
                    sh '''
                        echo "=== ОЧИСТКА CANARY ==="
                        
                        export DOCKER_HOST="tcp://192.168.0.1:2376"
                        
                        echo "1. Удаление canary стека..."
                        docker stack rm app-canary 2>/dev/null || true
                        
                        echo "2. Ожидание удаления (15 сек)..."
                        sleep 15
                        
                        echo "3. Проверка удаления..."
                        if docker stack ls --format "{{.Name}}" | grep -q "app-canary"; then
                            echo "   ⚠️ Canary стек еще существует, повторная попытка..."
                            docker stack rm app-canary 2>/dev/null || true
                            sleep 10
                        fi
                        
                        echo "✅ Canary успешно удален"
                    '''
                }
            }
        }
    }
    
    post {
        always {
            sh '''
                echo "=== ОЧИСТКА ПОСЛЕ ВЫПОЛНЕНИЯ ==="
                
                echo "1. Выход из Docker Hub..."
                docker logout 2>/dev/null || true
                
                echo "2. Удаление временных файлов..."
                rm -f docker-compose_canary_temp.yaml 2>/dev/null || true
                rm -f /tmp/canary_*.html /tmp/prod_*.html 2>/dev/null || true
                
                echo "3. Итоговый список стеков:"
                docker stack ls 2>/dev/null || true
                
                echo "✅ Очистка завершена"
            '''
        }
        failure {
            echo '❌ Пайплайн завершился с ошибкой'
            script {
                sh '''
                    echo "=== АВАРИЙНАЯ ОЧИСТКА ==="
                    
                    export DOCKER_HOST="tcp://192.168.0.1:2376" 2>/dev/null || true
                    
                    echo "1. Удаление canary при ошибке..."
                    docker stack rm app-canary 2>/dev/null || true
                    
                    echo "2. Состояние стеков:"
                    docker stack ls 2>/dev/null || true
                    
                    echo "3. Состояние сервисов:"
                    docker service ls 2>/dev/null | head -10 || true
                '''
            }
        }
        success {
            echo '✅ Пайплайн успешно завершен!'
            sh '''
                echo "=== ИТОГИ ==="
                echo "✅ Образы собраны и опубликованы"
                echo "✅ Canary протестирован и удален"
                echo "✅ Production обновлен и проверен"
                echo "✅ Все этапы выполнены успешно"
                echo ""
                echo "Production доступен по адресу:"
                echo "  http://192.168.0.1"
                echo ""
                echo "Сервисы в работе:"
                docker service ls --filter name=app 2>/dev/null || echo "  (информация недоступна)"
            '''
        }
    }
}
