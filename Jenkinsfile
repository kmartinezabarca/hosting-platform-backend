// Jenkinsfile — ROKE Industries Hosting Platform API
// Repo: github.com/kmartinezabarca/hosting-platform-backend
// Branches: develop → staging, master → producción

pipeline {
    agent {
        docker {
            image 'roke-jenkins-agent-php:latest'
            args '-v /var/run/docker.sock:/var/run/docker.sock -v /opt/stacks/jenkins/workspace-cache/composer:/home/builder/.composer -v /opt/apps:/opt/apps:rw'
            reuseNode true
        }
    }

    environment {
        STAGING_PATH = '/opt/apps/api-staging'
        PROD_PATH    = '/opt/apps/api'
        STAGING_URL  = 'https://api.rokeindustries.dev'
        PROD_URL     = 'https://api.rokeindustries.com'
        COMPOSER_NO_INTERACTION = '1'
        COMPOSER_MEMORY_LIMIT   = '-1'
    }

    options {
        buildDiscarder(logRotator(numToKeepStr: '10'))
        disableConcurrentBuilds()
        timeout(time: 30, unit: 'MINUTES')
        timestamps()
    }

    parameters {
        choice(
            name: 'DEPLOY_ENV',
            choices: ['none', 'staging', 'production'],
            description: '''
                none       → Solo build y tests, sin deploy
                staging    → Deploy a api.rokeindustries.dev
                production → Deploy a api.rokeindustries.com (requiere branch master)
            '''
        )
        booleanParam(name: 'RUN_MIGRATIONS', defaultValue: true,  description: 'Correr php artisan migrate (--force) tras deploy')
        booleanParam(name: 'RUN_TESTS',      defaultValue: false, description: 'Ejecutar phpunit/pest antes del deploy')
        booleanParam(name: 'KEEP_RELEASES',  defaultValue: true,  description: 'Mantener últimos 5 releases (rollback)')
    }

    stages {

        // ── STAGE 1: Checkout ─────────────────────────────────
        stage('Checkout') {
            steps {
                script {
                    echo "📦 Obteniendo código fuente..."
                    checkout scm
                    sh '''
                        echo "Branch:  $(git rev-parse --abbrev-ref HEAD)"
                        echo "Commit:  $(git rev-parse --short HEAD)"
                        echo "Autor:   $(git log -1 --pretty=format:'%an')"
                        echo "Mensaje: $(git log -1 --pretty=format:'%s')"
                    '''
                    env.RELEASE_TS   = sh(returnStdout: true, script: "date +%Y%m%d_%H%M%S").trim()
                    env.GIT_SHORT    = sh(returnStdout: true, script: "git rev-parse --short HEAD").trim()
                    env.RELEASE_NAME = "${env.RELEASE_TS}_${env.GIT_SHORT}"
                    echo "📌 Release name: ${env.RELEASE_NAME}"
                }
            }
        }

        // ── STAGE 2: Validación branch vs entorno ─────────────
        stage('Validate Environment') {
            steps {
                script {
                    def branch = sh(returnStdout: true, script: "git rev-parse --abbrev-ref HEAD").trim()
                    if (params.DEPLOY_ENV == 'production' && branch != 'master' && branch != 'HEAD') {
                        error("❌ Solo se puede desplegar a producción desde branch 'master'. Branch actual: ${branch}")
                    }
                    echo "✅ Deploy target: ${params.DEPLOY_ENV} | Branch: ${branch}"
                }
            }
        }

        // ── STAGE 3: Composer install ─────────────────────────
        stage('Composer Install') {
            steps {
                echo "📥 Instalando dependencias PHP..."
                sh '''
                    php --version | head -1
                    composer --version

                    if [ "${DEPLOY_ENV}" = "production" ]; then
                        composer install \
                            --no-dev \
                            --no-scripts \
                            --optimize-autoloader \
                            --prefer-dist \
                            --no-interaction
                    else
                        composer install \
                            --no-scripts \
                            --optimize-autoloader \
                            --prefer-dist \
                            --no-interaction
                    fi

                    echo "✅ Composer install completado"
                '''
            }
        }

        // ── STAGE 4: Tests (opcional) ─────────────────────────
        stage('Tests') {
            when { expression { params.RUN_TESTS } }
            steps {
                echo "🧪 Ejecutando tests..."
                sh '''
                    cp .env.example .env.testing 2>/dev/null || true
                    php artisan key:generate --env=testing --no-interaction || true
                    ./vendor/bin/phpunit --testdox || true
                '''
            }
        }

        // ── STAGE 5: Deploy Staging ───────────────────────────
        stage('Deploy Staging') {
            when { expression { params.DEPLOY_ENV == 'staging' } }
            steps {
                echo "🚀 Desplegando a STAGING: ${STAGING_URL}"
                sh """
                    set -e
                    RELEASE_DIR="${STAGING_PATH}/releases/${env.RELEASE_NAME}"

                    echo "📂 Creando release dir: \$RELEASE_DIR"
                    mkdir -p "\$RELEASE_DIR"

                    echo "📦 Copiando archivos..."
                    cp -r . "\$RELEASE_DIR/"

                    echo "🔗 Linkeando shared files..."
                    ln -sf "${STAGING_PATH}/shared/.env"            "\$RELEASE_DIR/.env"
                    rm -rf "\$RELEASE_DIR/storage"
                    ln -sf "${STAGING_PATH}/shared/storage"         "\$RELEASE_DIR/storage"
                    rm -rf "\$RELEASE_DIR/bootstrap/cache"
                    ln -sf "${STAGING_PATH}/shared/bootstrap-cache" "\$RELEASE_DIR/bootstrap/cache"

                    cd "\$RELEASE_DIR"

                    echo "🔑 Verificando APP_KEY..."
                    grep -q "^APP_KEY=base64:" .env || php artisan key:generate --force --no-interaction

                    echo "🔍 Descubriendo packages..."
                    php artisan package:discover --ansi || true

                    echo "🗄️  Migraciones..."
                    if [ "${params.RUN_MIGRATIONS}" = "true" ]; then
                        php artisan migrate --force --no-interaction
                    fi

                    echo "⚡ Optimizando..."
                    php artisan config:cache
                    php artisan route:cache
                    php artisan view:cache
                    php artisan event:cache

                    echo "🔄 Atomic switch..."
                    ln -snf "\$RELEASE_DIR" "${STAGING_PATH}/current"
                    echo "Symlink current → \$RELEASE_DIR"

                    echo "♻️  Reload servicios..."
                    sudo /usr/bin/systemctl reload php8.2-fpm
                    sudo /usr/bin/systemctl reload nginx

                    echo "🧹 Limpiando releases antiguos (mantener 5)..."
                    cd "${STAGING_PATH}/releases"
                    ls -1t | tail -n +6 | xargs -r rm -rf

                    echo "✅ Deploy staging completado: ${env.RELEASE_NAME}"
                    ls -la "${STAGING_PATH}/current"
                """
                echo "✅ Staging disponible en: ${STAGING_URL}"
            }
        }

        // ── STAGE 6: Deploy Producción ────────────────────────
        stage('Deploy Production') {
            when { expression { params.DEPLOY_ENV == 'production' } }
            steps {
                script {
                    echo "⚠️  Desplegando a PRODUCCIÓN"
                    input(
                        message: "¿Confirmas el deploy a producción?\n${PROD_URL}\nRelease: ${env.RELEASE_NAME}",
                        ok: '🚀 Sí, desplegar'
                    )
                }
                echo "🚀 Desplegando a PRODUCCIÓN: ${PROD_URL}"
                sh """
                    set -e
                    RELEASE_DIR="${PROD_PATH}/releases/${env.RELEASE_NAME}"

                    echo "📂 Creando release dir: \$RELEASE_DIR"
                    mkdir -p "\$RELEASE_DIR"

                    echo "📦 Copiando archivos..."
                    cp -r . "\$RELEASE_DIR/"

                    echo "🔗 Linkeando shared files..."
                    ln -sf "${PROD_PATH}/shared/.env"            "\$RELEASE_DIR/.env"
                    rm -rf "\$RELEASE_DIR/storage"
                    ln -sf "${PROD_PATH}/shared/storage"         "\$RELEASE_DIR/storage"
                    rm -rf "\$RELEASE_DIR/bootstrap/cache"
                    ln -sf "${PROD_PATH}/shared/bootstrap-cache" "\$RELEASE_DIR/bootstrap/cache"

                    cd "\$RELEASE_DIR"

                    echo "🔑 Verificando APP_KEY..."
                    grep -q "^APP_KEY=base64:" .env || php artisan key:generate --force --no-interaction

                    echo "🔍 Descubriendo packages..."
                    php artisan package:discover --ansi || true

                    echo "🗄️  Migraciones..."
                    if [ "${params.RUN_MIGRATIONS}" = "true" ]; then
                        php artisan migrate --force --no-interaction
                    fi

                    echo "⚡ Optimizando..."
                    php artisan config:cache
                    php artisan route:cache
                    php artisan view:cache
                    php artisan event:cache

                    echo "🔄 Atomic switch..."
                    ln -snf "\$RELEASE_DIR" "${PROD_PATH}/current"
                    echo "Symlink current → \$RELEASE_DIR"

                    echo "♻️  Reload servicios..."
                    sudo /usr/bin/systemctl reload php8.2-fpm
                    sudo /usr/bin/systemctl reload nginx
                    sudo /usr/bin/supervisorctl restart laravel-prod: 2>/dev/null || true

                    echo "🧹 Limpiando releases antiguos (mantener 5)..."
                    cd "${PROD_PATH}/releases"
                    ls -1t | tail -n +6 | xargs -r rm -rf

                    echo "✅ Deploy producción completado: ${env.RELEASE_NAME}"
                    ls -la "${PROD_PATH}/current"
                """
                echo "✅ Producción actualizada: ${PROD_URL}"
            }
        }
    }

    post {
        success {
            echo "✅ Pipeline #${env.BUILD_NUMBER} completado — Release: ${env.RELEASE_NAME ?: 'N/A'}"
        }
        failure {
            echo "❌ Pipeline #${env.BUILD_NUMBER} falló en: ${env.STAGE_NAME}"
        }
        cleanup {
            echo "🧹 Limpiando workspace..."
            sh 'rm -f /tmp/release.tgz 2>/dev/null || true'
            cleanWs()
        }
    }
}
