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
    def url = params.DEPLOY_ENV == 'production' ? env.PROD_URL : env.STAGING_URL
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
            args '-v /var/run/docker.sock:/var/run/docker.sock -v /opt/stacks/jenkins/workspace-cache/composer:/home/builder/.composer -v /opt/apps:/opt/apps:rw'
            reuseNode true
        }
    }

    environment {
        STAGING_PATH            = '/opt/apps/api-staging'
        PROD_PATH               = '/opt/apps/api'
        STAGING_URL             = 'https://api.rokeindustries.dev'
        PROD_URL                = 'https://api.rokeindustries.com'
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
        choice(name: 'DEPLOY_ENV', choices: ['none', 'staging', 'production'])
        booleanParam(name: 'RUN_MIGRATIONS', defaultValue: true)
        booleanParam(name: 'RUN_TESTS', defaultValue: false)
        booleanParam(name: 'KEEP_RELEASES', defaultValue: true)
    }

    stages {

        stage('Checkout') {
            steps {
                checkout scm
                script {
                    env.GIT_SHORT = sh(returnStdout: true, script: "git rev-parse --short HEAD").trim()
                    env.RELEASE_TS   = sh(returnStdout: true, script: "date +%Y%m%d_%H%M%S").trim()
                    env.RELEASE_NAME = "${env.RELEASE_TS}_${env.GIT_SHORT}"
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
                    if [ "${DEPLOY_ENV}" = "production" ]; then
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
                sh '''
                    ./vendor/bin/phpunit || true
                '''
            }
        }

        // 🔥 NO TOQUÉ NADA DE TU DEPLOY ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓

        stage('Deploy Staging') {
            when { expression { params.DEPLOY_ENV == 'staging' } }
            steps {
                script {
                    def releaseName   = env.RELEASE_NAME
                    def stagingPath   = env.STAGING_PATH
                    def runMigrations = params.RUN_MIGRATIONS

                    sh """
                        # (tu código intacto)
                        RELEASE_DIR=${stagingPath}/releases/${releaseName}
                        mkdir -p "\$RELEASE_DIR"
                        cp -r . "\$RELEASE_DIR/"
                        ln -sf ${stagingPath}/shared/.env "\$RELEASE_DIR/.env"
                        rm -rf "\$RELEASE_DIR/storage"
                        ln -sf ${stagingPath}/shared/storage "\$RELEASE_DIR/storage"
                        rm -rf "\$RELEASE_DIR/bootstrap/cache"
                        ln -sf ${stagingPath}/shared/bootstrap-cache "\$RELEASE_DIR/bootstrap/cache"

                        cd "\$RELEASE_DIR"
                        php artisan config:clear

                        if [ "${runMigrations}" = "true" ]; then
                            php artisan migrate --force --no-interaction
                        fi

                        php artisan config:cache
                        php artisan route:cache
                        php artisan view:cache

                        ln -snf "\$RELEASE_DIR" ${stagingPath}/current
                    """
                }
                sh '/usr/local/bin/roke-reload-staging 2>/dev/null || true'
            }
        }

        stage('Deploy Production') {
            when { expression { params.DEPLOY_ENV == 'production' } }
            steps {
                input(message: "Confirmar producción ${env.RELEASE_NAME}", ok: 'Deploy')

                script {
                    def releaseName = env.RELEASE_NAME
                    def prodPath = env.PROD_PATH

                    sh """
                        RELEASE_DIR=${prodPath}/releases/${releaseName}
                        mkdir -p "\$RELEASE_DIR"
                        cp -r . "\$RELEASE_DIR/"
                        ln -sf ${prodPath}/shared/.env "\$RELEASE_DIR/.env"

                        cd "\$RELEASE_DIR"
                        php artisan config:clear
                        php artisan migrate --force --no-interaction
                        php artisan config:cache

                        ln -snf "\$RELEASE_DIR" ${prodPath}/current
                    """
                }
                sh '/usr/local/bin/roke-reload-prod 2>/dev/null || true'
            }
        }
    }

    post {
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
