// ==========================
// 🔔 DISCORD NOTIFY FUNCTION
// ==========================
def notify(status, extra="") {
    def colorMap = [
        "START": 3447003,
        "SUCCESS": 3066993,
        "FAILURE": 15158332,
        "WARNING": 15844367,
        "CRITICAL": 10038562
    ]

    def color = colorMap[status] ?: 3447003
    def url = params.DEPLOY_ENV == 'production' ? env.PROD_URL : env.DEV_URL
    def isProd = params.DEPLOY_ENV == 'production'

    def payload = """
    {
      "embeds": [{
        "title": "🚀 API Deploy ${status}",
        "color": ${color},
        "fields": [
          {"name":"Proyecto","value":"${env.JOB_NAME}","inline":true},
          {"name":"Build","value":"#${env.BUILD_NUMBER}","inline":true},
          {"name":"Env","value":"${params.DEPLOY_ENV}","inline":true},
          {"name":"Release","value":"${env.RELEASE_NAME ?: 'N/A'}","inline":true},
          {"name":"Commit","value":"${env.GIT_SHORT ?: 'N/A'}","inline":true},
          {"name":"Duración","value":"${currentBuild.durationString ?: 'running'}","inline":true},
          {"name":"URL","value":"${url}","inline":true},
          {"name":"Build URL","value":"${env.BUILD_URL}","inline":false},
          {"name":"Extra","value":"${extra}","inline":false}
        ]
      }]
    }
    """

    sh """
      curl -s -H 'Content-Type: application/json' \
           -X POST \
           -d '${payload}' \
           ${env.DISCORD_WEBHOOK}
    """
}

// ==========================
// 🚀 PIPELINE
// ==========================
pipeline {
    agent {
        docker {
            image 'roke-jenkins-agent-php:latest'
            args '-v /var/run/docker.sock:/var/run/docker.sock -v /opt/stacks/jenkins/workspace-cache/composer:/home/builder/.composer -v /opt/apps:/opt/apps:rw --group-add 988'
            reuseNode true
        }
    }

    environment {
        PROD_PATH               = '/opt/apps/api'
        PROD_URL                = 'https://api.rokeindustries.com'
        DEV_PATH                = '/opt/apps/api-dev'
        DEV_URL                 = 'https://api.rokeindustries.dev'
        DEV_HOST                = '100.72.162.112'
        DEV_USER                = 'rokedev'
        COMPOSER_NO_INTERACTION = '1'
        COMPOSER_MEMORY_LIMIT   = '-1'
        DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/1501364059117715558/W_w1xbGHR_jifhNtdE9koiPjoaiXB2fYEJ62mAsMn9zSeOnQxLXasOWpPN9a-Is35Wsd'
    }

    options {
        buildDiscarder(logRotator(numToKeepStr: '10'))
        disableConcurrentBuilds()
        timeout(time: 30, unit: 'MINUTES')
        timestamps()
    }

    parameters {
        choice(name: 'DEPLOY_ENV', choices: ['none', 'development', 'production'])
        booleanParam(name: 'RUN_MIGRATIONS', defaultValue: true)
        booleanParam(name: 'RUN_TESTS', defaultValue: true)
        string(name: 'COVERAGE_MIN', defaultValue: '10', description: 'Cobertura minima de lineas para permitir deploy')
        booleanParam(name: 'KEEP_RELEASES', defaultValue: true)
    }

    stages {

        stage('Checkout') {
            steps {
                checkout scm
                script {
                    env.GIT_SHORT    = sh(returnStdout: true, script: "git rev-parse --short HEAD").trim()
                    env.APP_VERSION  = sh(returnStdout: true, script: "git describe --tags --always 2>/dev/null || echo v0.0.0").trim()
                    env.RELEASE_TS   = sh(returnStdout: true, script: "date +%Y%m%d_%H%M%S").trim()
                    env.RELEASE_NAME = "${env.APP_VERSION}_${env.RELEASE_TS}_${env.GIT_SHORT}"
                }
            }
        }

        stage('Notify START') {
            steps { script { notify("START") } }
        }

        stage('Validate Environment') {
            steps {
                script {
                    def branch = sh(returnStdout: true, script: "git rev-parse --abbrev-ref HEAD").trim()
                    if (params.DEPLOY_ENV == 'production' && branch != 'master' && branch != 'HEAD') {
                        error("❌ Solo producción desde master")
                    }
                }
            }
        }

        stage('Composer Install') {
            steps {
                sh '''
                    rm -rf vendor
                    if [ "${DEPLOY_ENV}" = "production" ] && [ "${RUN_TESTS}" != "true" ]; then
                        composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist
                    else
                        composer install --no-scripts --optimize-autoloader --prefer-dist
                    fi
                '''
            }
        }

        stage('Tests') {
            when { expression { params.RUN_TESTS } }
            steps {
                script {
                    docker.image('mysql:8.0').withRun(
                        '-e MYSQL_ROOT_PASSWORD=secret ' +
                        '-e MYSQL_DATABASE=hosting_platform_test ' +
                        '-e MYSQL_USER=laravel ' +
                        '-e MYSQL_PASSWORD=secret'
                    ) { mysqlContainer ->
                        sh """
                            mkdir -p build/logs build/coverage bootstrap/cache

                            until docker exec ${mysqlContainer.id} mysqladmin ping -h 127.0.0.1 -u root -psecret --silent 2>/dev/null; do
                                echo "Esperando MySQL..."
                                sleep 3
                            done

                            MYSQL_IP=\$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${mysqlContainer.id})

                            cp .env.testing .env

                            sed -i "s/__MYSQL_IP__/\$MYSQL_IP/g" phpunit.xml

                            if ! php -m | grep -qiE 'pcov|xdebug'; then
                                echo "❌ No hay driver de cobertura (pcov/xdebug) en el agente."
                                exit 1
                            fi

                            XDEBUG_MODE=coverage php -d memory_limit=512M ./vendor/bin/phpunit \\
                                --log-junit build/logs/junit.xml \\
                                --coverage-clover build/coverage/clover.xml \\
                                --coverage-cobertura build/coverage/cobertura.xml

                            php scripts/ci/check-coverage.php build/coverage/clover.xml "${params.COVERAGE_MIN}"
                        """
                    }
                }
            }
        }

        stage('Deploy Development') {
            when { expression { params.DEPLOY_ENV == 'development' } }
            steps {
                script {
                    def releaseName   = env.RELEASE_NAME
                    def devPath       = env.DEV_PATH
                    def devHost       = env.DEV_HOST
                    def devUser       = env.DEV_USER
                    def runMigrations = params.RUN_MIGRATIONS

                    withCredentials([sshUserPrivateKey(
                        credentialsId: 'mac-mini-deploy-key',
                        keyFileVariable: 'SSH_KEY'
                    )]) {
                        sh """
                            ssh -i \$SSH_KEY \
                                -o StrictHostKeyChecking=no \
                                -o ConnectTimeout=10 \
                                ${devUser}@${devHost} \
                                "mkdir -p ${devPath}/releases/${releaseName}/bootstrap/cache && chmod 777 ${devPath}/releases/${releaseName}/bootstrap/cache"
                        """

                        sh """
                            rsync -az \
                                --exclude='.git' \
                                --exclude='node_modules' \
                                --exclude='vendor' \
                                --exclude='.env' \
                                --exclude='storage' \
                                -e "ssh -i \$SSH_KEY -o StrictHostKeyChecking=no" \
                                ./ ${devUser}@${devHost}:${devPath}/releases/${releaseName}/
                        """

                        sh """
                            ssh -i \$SSH_KEY \
                                -o StrictHostKeyChecking=no \
                                ${devUser}@${devHost} bash << 'REMOTE'
                                    set -e
                                    RELEASE_DIR=${devPath}/releases/${releaseName}

                                    ln -sf ${devPath}/shared/.env \$RELEASE_DIR/.env
                                    rm -rf \$RELEASE_DIR/storage
                                    ln -sf ${devPath}/shared/storage \$RELEASE_DIR/storage

                                    cd \$RELEASE_DIR

                                    composer install --no-scripts --optimize-autoloader --prefer-dist

                                    export APP_VERSION='${env.APP_VERSION}'
                                    export APP_GIT_COMMIT='${env.GIT_SHORT}'
                                    export APP_BUILD_ID='${env.BUILD_NUMBER}'
                                    export APP_BUILD_TIMESTAMP='${env.RELEASE_TS}'

                                    if [ "${runMigrations}" = "true" ]; then
                                        export DB_HOST=127.0.0.1
                                        php artisan deploy:refresh --migrate --skip-restarts --no-interaction
                                    else
                                        php artisan deploy:refresh --skip-restarts --no-interaction
                                    fi

                                    ln -snf \$RELEASE_DIR ${devPath}/current

                                    cd ${devPath}/current
                                    php artisan queue:restart --no-interaction || true
                                    php artisan reverb:restart --no-interaction || true

                                    ls -dt ${devPath}/releases/*/ | tail -n +6 | xargs rm -rf || true
REMOTE
                        """

                        sh """
                            ssh -i \$SSH_KEY \
                                -o StrictHostKeyChecking=no \
                                ${devUser}@${devHost} \
                                "sudo /usr/local/bin/roke-reload-dev 2>/dev/null || true"
                        """
                    }
                }
            }
        }

        stage('Deploy Production') {
            when { expression { params.DEPLOY_ENV == 'production' } }
            steps {
                input(message: "Confirmar producción ${env.RELEASE_NAME}", ok: 'Deploy')

                script {
                    def releaseName = env.RELEASE_NAME
                    def prodPath    = env.PROD_PATH

                    sh """
                        RELEASE_DIR=${prodPath}/releases/${releaseName}
                        mkdir -p "\$RELEASE_DIR"
                        cp -r . "\$RELEASE_DIR/"
                        ln -sf ${prodPath}/shared/.env "\$RELEASE_DIR/.env"

                        cd "\$RELEASE_DIR"
                        composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist

                        # Limpiar cache de bootstrap para regenerar limpio sin paquetes dev
                        rm -f bootstrap/cache/packages.php
                        rm -f bootstrap/cache/services.php

                        export APP_VERSION='${env.APP_VERSION}'
                        export APP_GIT_COMMIT='${env.GIT_SHORT}'
                        export APP_BUILD_ID='${env.BUILD_NUMBER}'
                        export APP_BUILD_TIMESTAMP='${env.RELEASE_TS}'

                        php artisan deploy:refresh --migrate --skip-restarts --no-interaction

                        ln -snf "\$RELEASE_DIR" ${prodPath}/current

                        cd ${prodPath}/current
                        php artisan queue:restart --no-interaction || true
                        php artisan reverb:restart --no-interaction || true

                        ls -dt ${prodPath}/releases/*/ | tail -n +6 | xargs rm -rf || true
                    """
                }
                sh '/usr/local/bin/roke-reload-prod 2>/dev/null || true'
            }
        }
    }

    post {
        always {
            junit allowEmptyResults: true, testResults: 'build/logs/junit.xml'
            archiveArtifacts allowEmptyArchive: true, artifacts: 'build/logs/*.xml, build/coverage/*.xml'
        }

        success {
            script {
                notify("SUCCESS")
                def durationSec = currentBuild.duration / 1000
                if (durationSec > 120) {
                    notify("WARNING", "Deploy lento: ${durationSec}s")
                }
            }
        }

        failure {
            script {
                notify("FAILURE", "Error en ${env.STAGE_NAME}")
                def prev = currentBuild.previousBuild
                if (prev && prev.result == "FAILURE") {
                    notify("CRITICAL", "🔥 Fallos consecutivos API")
                }
            }
        }

        cleanup {
            cleanWs()
        }
    }
}
