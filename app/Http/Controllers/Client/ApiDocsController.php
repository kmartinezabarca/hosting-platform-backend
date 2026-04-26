<?php

namespace App\Http\Controllers\Client;

use Illuminate\Routing\Controller as BaseController;

class ApiDocsController extends BaseController
{
    public function json()
    {
        $url = env('APP_URL', 'http://localhost:8000');

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'ROKE Industries API',
                'version' => '1.0.0',
                'description' => 'API para la plataforma de hosting de ROKE Industries.',
            ],
            'servers' => [
                ['url' => $url.'/api', 'description' => 'Servidor principal'],
            ],
            'paths' => $this->getPaths(),
            'components' => [
                'schemas' => $this->getSchemas(),
            ],
        ];

        return response()->json($spec);
    }

    private function getPaths(): array
    {
        return [
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
                                        'password' => ['type' => 'string', 'format' => 'password', 'example' => 'Password123!'],
                                        'password_confirmation' => ['type' => 'string', 'example' => 'Password123!'],
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
                    'responses' => [
                        '200' => ['description' => 'Lista de categorías'],
                    ],
                ],
            ],
            '/categories/with-plans' => [
                'get' => [
                    'summary' => 'Categorías con planes',
                    'tags' => ['Catálogos'],
                    'responses' => [
                        '200' => ['description' => 'Categorías con sus planes'],
                    ],
                ],
            ],
            '/billing-cycles' => [
                'get' => [
                    'summary' => 'Listar ciclos de facturación',
                    'tags' => ['Catálogos'],
                    'responses' => [
                        '200' => ['description' => 'Lista de ciclos'],
                    ],
                ],
            ],
            '/service-plans' => [
                'get' => [
                    'summary' => 'Listar planes de servicio',
                    'tags' => ['Catálogos'],
                    'responses' => [
                        '200' => ['description' => 'Lista de planes'],
                    ],
                ],
            ],
            '/service-plans/{uuid}' => [
                'get' => [
                    'summary' => 'Ver plan específico',
                    'tags' => ['Catálogos'],
                    'parameters' => [
                        ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Detalles del plan'],
                        '404' => ['description' => 'Plan no encontrado'],
                    ],
                ],
            ],
            '/service-plans/category/{categorySlug}' => [
                'get' => [
                    'summary' => 'Planes por categoría',
                    'tags' => ['Catálogos'],
                    'parameters' => [
                        ['name' => 'categorySlug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Planes de la categoría'],
                    ],
                ],
            ],
            '/payments/methods' => [
                'get' => [
                    'summary' => 'Listar métodos de pago',
                    'tags' => ['Pagos'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => ['description' => 'Lista de métodos de pago'],
                        '401' => ['description' => 'No autorizado'],
                    ],
                ],
                'post' => [
                    'summary' => 'Agregar método de pago',
                    'tags' => ['Pagos'],
                    'security' => [['sanctum' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['stripe_payment_method_id'],
                                    'properties' => [
                                        'stripe_payment_method_id' => ['type' => 'string', 'example' => 'pm_xxx'],
                                        'set_as_default' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Método de pago agregado'],
                    ],
                ],
            ],
            '/invoices' => [
                'get' => [
                    'summary' => 'Listar facturas',
                    'tags' => ['Facturación'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => ['description' => 'Lista de facturas'],
                    ],
                ],
            ],
            '/services' => [
                'get' => [
                    'summary' => 'Listar servicios del usuario',
                    'tags' => ['Servicios'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => ['description' => 'Lista de servicios'],
                    ],
                ],
            ],
            '/services/{uuid}' => [
                'get' => [
                    'summary' => 'Ver servicio',
                    'tags' => ['Servicios'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Detalles del servicio'],
                    ],
                ],
            ],
            '/tickets' => [
                'get' => [
                    'summary' => 'Listar tickets',
                    'tags' => ['Soporte'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => ['description' => 'Lista de tickets'],
                    ],
                ],
                'post' => [
                    'summary' => 'Crear ticket',
                    'tags' => ['Soporte'],
                    'security' => [['sanctum' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['subject', 'message', 'priority'],
                                    'properties' => [
                                        'subject' => ['type' => 'string', 'example' => 'Problema con mi servicio'],
                                        'message' => ['type' => 'string', 'example' => 'Descripción del problema...'],
                                        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Ticket creado'],
                    ],
                ],
            ],
            '/user/profile' => [
                'get' => [
                    'summary' => 'Obtener perfil',
                    'tags' => ['Usuario'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => ['description' => 'Datos del perfil'],
                    ],
                ],
                'put' => [
                    'summary' => 'Actualizar perfil',
                    'tags' => ['Usuario'],
                    'security' => [['sanctum' => []]],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'first_name' => ['type' => 'string'],
                                        'last_name' => ['type' => 'string'],
                                        'phone' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Perfil actualizado'],
                    ],
                ],
            ],
        ];
    }

    private function getSchemas(): array
    {
        return [
            'Category' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'icon' => ['type' => 'string'],
                ],
            ],
            'ServicePlan' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'base_price' => ['type' => 'number', 'format' => 'float'],
                    'setup_fee' => ['type' => 'number', 'format' => 'float'],
                ],
            ],
            'Invoice' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'invoice_number' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'total' => ['type' => 'number'],
                    'currency' => ['type' => 'string'],
                    'due_date' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
            'Ticket' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'priority' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'first_name' => ['type' => 'string'],
                    'last_name' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
