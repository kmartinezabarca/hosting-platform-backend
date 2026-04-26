<?php

header('Content-Type: application/json');

$spec = json_encode([
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'ROKE Industries API',
        'version' => '1.0.0',
        'description' => 'API para la plataforma de hosting de ROKE Industries.',
    ],
    'servers' => [
        ['url' => '/api', 'description' => 'Servidor principal'],
    ],
    'paths' => [
        '/auth/register' => [
            'post' => [
                'summary' => 'Registrar usuario',
                'tags' => ['Auth'],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['first_name', 'last_name', 'email', 'password', 'password_confirmation'],
                                'properties' => [
                                    'first_name' => ['type' => 'string', 'example' => 'Juan'],
                                    'last_name' => ['type' => 'string', 'example' => 'Pérez'],
                                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'juan@example.com'],
                                    'password' => ['type' => 'string', 'format' => 'password'],
                                    'password_confirmation' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '201' => ['description' => 'Usuario creado'],
                    '422' => ['description' => 'Error de validación'],
                ],
            ],
        ],
        '/auth/login' => [
            'post' => [
                'summary' => 'Iniciar sesión',
                'tags' => ['Auth'],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['email', 'password'],
                                'properties' => [
                                    'email' => ['type' => 'string', 'format' => 'email'],
                                    'password' => ['type' => 'string', 'format' => 'password'],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => ['description' => 'Login exitoso'],
                    '422' => ['description' => 'Credenciales inválidas'],
                ],
            ],
        ],
        '/categories' => [
            'get' => [
                'summary' => 'Listar categorías',
                'tags' => ['Catálogos'],
                'responses' => ['200' => ['description' => 'Lista de categorías']],
            ],
        ],
        '/categories/with-plans' => [
            'get' => [
                'summary' => 'Categorías con planes',
                'tags' => ['Catálogos'],
                'responses' => ['200' => ['description' => 'Categorías con sus planes']],
            ],
        ],
        '/billing-cycles' => [
            'get' => [
                'summary' => 'Listar ciclos de facturación',
                'tags' => ['Catálogos'],
                'responses' => ['200' => ['description' => 'Lista de ciclos']],
            ],
        ],
        '/service-plans' => [
            'get' => [
                'summary' => 'Listar planes de servicio',
                'tags' => ['Catálogos'],
                'responses' => ['200' => ['description' => 'Lista de planes']],
            ],
        ],
        '/service-plans/category/{categorySlug}' => [
            'get' => [
                'summary' => 'Planes por categoría',
                'tags' => ['Catálogos'],
                'parameters' => [
                    ['name' => 'categorySlug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'responses' => ['200' => ['description' => 'Planes de la categoría']],
            ],
        ],
    ],
    'components' => [
        'schemas' => [
            'Category' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
            'ServicePlan' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'base_price' => ['type' => 'number'],
                ],
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo $spec;
