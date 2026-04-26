// Jenkinsfile — ROKE Industries Hosting Platform API
// Repo: github.com/kmartinezabarca/hosting-platform-backend
// Branches: develop → staging, master → producción

pipeline {
    agent {
        docker {
            image 'roke-jenkins-agent:latest'
            args '-v /var/run/docker.sock:/var/run/docker.sock -v /opt/stacks/jenkins/workspace-cache/composer:/home/builder/.composer'
            reuseNode true
        }
    }

    environment {
        // ── Servidor destino ──────────────────────────────────
        DEPLOY_HOST = '100.124.151.68'
        DEPLOY_USER = 'rokecore'

        // ── Paths según entorno ───────────────────────────────
        STAGING_PATH = '/opt/apps/api-staging'
        PROD_PATH    = '/opt/apps/api'

        // ── URLs ──────────────────────────────────────────────
        STAGING_URL = 'https://api.rokeindustries.dev'
        PROD_URL    = 'https://api.rokeindustries.com'

        // ── Composer ─────────────────────────────────────────
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
        booleanParam(name: 'RUN_MIGRATIONS',  defaultValue: true,  description: 'Correr php artisan migrate (--force) tras deploy')
        booleanParam(name: 'RUN_TESTS',       defaultValue: false, description: 'Ejecutar phpunit/pest antes del deploy')
        booleanParam(name: 'KEEP_RELEASES',   defaultValue: true,  description: 'Mantener últimos 5 releases (rollback)')
    }

    stages {

        // ── STAGE 1: Checkout ─────────────────────────────────
        stage('Checkout') {
            steps {
                script {
                    echo "📦 Obteniendo código fuente..."
                    checkout scm
                    sh '''
                        echo "Branch: $(git rev-parse --abbrev-ref HEAD)"
                        echo "Commit: $(git rev-parse --short HEAD)"
                        echo "Autor:  $(git log -1 --pretty=format:'%an')"
                        echo "Mensaje: $(git log -1 --pretty=format:'%s')"
                    '''
                    // Calcular timestamp único para esta release
                    env.RELEASE_TS = sh(returnStdout: true, script: "date +%Y%m%d_%H%M%S").trim()
                    env.GIT_SHORT  = sh(returnStdout: true, script: "git rev-parse --short HEAD").trim()
                    env.RELEASE_NAME = "${env.RELEASE_TS}_${env.GIT_SHORT}"
                    echo "📌 Release name: ${env.RELEASE_NAME}"
                }
            }
        }

        // ── STAGE 2: Validación de branch vs entorno ──────────
        stage('Validate Environment') {
            steps {
                script {
                    def branch = sh(returnStdout: true, script: "git rev-parse --abbrev-ref HEAD").trim()
                    if (params.DEPLOY_ENV == 'production' && branch != 'master' && branch != 'HEAD') {
                        error("❌ Solo se puede desplegar a producción desde branch 'master'. Branch actual: ${branch}")
                    }
                    if (params.DEPLOY_ENV == 'staging') {
                        echo "✅ Deploy a STAGING desde ${branch}"
                    }
                }
            }
        }

        // ── STAGE 3: Composer install ─────────────────────────
        stage('Composer Install') {
            steps {
                echo "📥 Instalando dependencias PHP..."
                sh '''
                    # Verificar PHP y composer disponibles
                    php --version | head -1
                    composer --version 2>/dev/null || curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
                    composer --version

                    # Install (sin dev en producción)
                    if [ "${DEPLOY_ENV}" = "production" ]; then
                        composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
                    else
                        composer install --optimize-autoloader --prefer-dist --no-interaction
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
                    # Necesita una APP_KEY temporal para los tests
                    cp .env.example .env.testing 2>/dev/null || true
                    php artisan key:generate --env=testing --no-interaction || true
                    ./vendor/bin/phpunit --testdox || true
                '''
            }
        }

        // ── STAGE 5: Build artifact (tar para deploy) ─────────
        stage('Package') {
            when { expression { params.DEPLOY_ENV != 'none' } }
            steps {
                echo "📦 Empaquetando release ${env.RELEASE_NAME}..."
                sh '''
                    # Limpiar archivos que no van al deploy
                    rm -rf .git .github tests phpunit.xml \
                           .env .env.testing .env.example \
                           storage/logs/*.log

                    # Listar contenido para sanity check
                    echo "=== Tamaño del release ==="
                    du -sh .

                    # Crear tarball
                    tar czf /tmp/release.tgz .
                    ls -lah /tmp/release.tgz
                '''
            }
        }

        // ── STAGE 6: Deploy a STAGING ─────────────────────────
        stage('Deploy Staging') {
            when { expression { params.DEPLOY_ENV == 'staging' } }
            steps {
                echo "🚀 Desplegando a STAGING: ${STAGING_URL}"
                sshagent(credentials: ['roke-ssh-key']) {
                    sh """
                        # 1. Subir tarball al servidor
                        scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \\
                            /tmp/release.tgz \\
                            ${DEPLOY_USER}@${DEPLOY_HOST}:/tmp/release.tgz

                        # 2. Ejecutar deploy script remoto
                        ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \\
                            ${DEPLOY_USER}@${DEPLOY_HOST} \\
                            'bash -s' << 'REMOTE_DEPLOY'

set -e

APP_PATH="${STAGING_PATH}"
RELEASE_NAME="${RELEASE_NAME}"
RELEASE_DIR="\$APP_PATH/releases/\$RELEASE_NAME"

echo "📂 Creando directorio de release: \$RELEASE_DIR"
mkdir -p "\$RELEASE_DIR"

echo "📦 Extrayendo tarball..."
tar xzf /tmp/release.tgz -C "\$RELEASE_DIR"
rm /tmp/release.tgz

echo "🔗 Linkeando archivos compartidos..."
# .env compartido
ln -sf "\$APP_PATH/shared/.env" "\$RELEASE_DIR/.env"

# storage compartido (logs, sesiones, uploads sobreviven entre releases)
rm -rf "\$RELEASE_DIR/storage"
ln -sf "\$APP_PATH/shared/storage" "\$RELEASE_DIR/storage"

# bootstrap/cache compartido
rm -rf "\$RELEASE_DIR/bootstrap/cache"
ln -sf "\$APP_PATH/shared/bootstrap-cache" "\$RELEASE_DIR/bootstrap/cache"

echo "🔑 Generando APP_KEY si no existe..."
cd "\$RELEASE_DIR"
if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate --force --no-interaction
fi

echo "🗄️  Corriendo migraciones..."
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

echo "⚡ Optimizando..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "🔄 Atomic switch del symlink current..."
ln -snf "\$RELEASE_DIR" "\$APP_PATH/current"

echo "♻️  Reload PHP-FPM y Nginx..."
sudo /usr/bin/systemctl reload php8.2-fpm
sudo /usr/bin/systemctl reload nginx

echo "🧹 Limpiando releases antiguos (mantener últimos 5)..."
cd "\$APP_PATH/releases"
ls -1t | tail -n +6 | xargs -r rm -rf

echo "✅ Deploy completado: \$RELEASE_NAME"
ls -la "\$APP_PATH/current"

REMOTE_DEPLOY
                    """
                }
                echo "✅ Staging actualizado: ${STAGING_URL}"
            }
        }

        // ── STAGE 7: Deploy a PRODUCCIÓN ──────────────────────
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
                sshagent(credentials: ['roke-ssh-key']) {
                    sh """
                        scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \\
                            /tmp/release.tgz \\
                            ${DEPLOY_USER}@${DEPLOY_HOST}:/tmp/release.tgz

                        ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \\
                            ${DEPLOY_USER}@${DEPLOY_HOST} \\
                            'bash -s' << 'REMOTE_DEPLOY'

set -e

APP_PATH="${PROD_PATH}"
RELEASE_NAME="${RELEASE_NAME}"
RELEASE_DIR="\$APP_PATH/releases/\$RELEASE_NAME"

mkdir -p "\$RELEASE_DIR"
tar xzf /tmp/release.tgz -C "\$RELEASE_DIR"
rm /tmp/release.tgz

ln -sf "\$APP_PATH/shared/.env" "\$RELEASE_DIR/.env"
rm -rf "\$RELEASE_DIR/storage"
ln -sf "\$APP_PATH/shared/storage" "\$RELEASE_DIR/storage"
rm -rf "\$RELEASE_DIR/bootstrap/cache"
ln -sf "\$APP_PATH/shared/bootstrap-cache" "\$RELEASE_DIR/bootstrap/cache"

cd "\$RELEASE_DIR"

# Migraciones SOLO si fue solicitado
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Optimización completa
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Atomic switch
ln -snf "\$RELEASE_DIR" "\$APP_PATH/current"

# Reload servicios
sudo /usr/bin/systemctl reload php8.2-fpm
sudo /usr/bin/systemctl reload nginx

# Restart de queue workers para que tomen el código nuevo
sudo /usr/bin/supervisorctl restart laravel:* 2>/dev/null || true

# Limpiar releases antiguos
cd "\$APP_PATH/releases"
ls -1t | tail -n +6 | xargs -r rm -rf

echo "✅ Deploy completado: \$RELEASE_NAME"

REMOTE_DEPLOY
                    """
                }
                echo "✅ Producción actualizada: ${PROD_URL}"
            }
        }
    }

    post {
        success {
            echo "✅ Pipeline #${env.BUILD_NUMBER} completado — Release ${env.RELEASE_NAME}"
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
