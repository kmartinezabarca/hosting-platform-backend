<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Hosting Platform')</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8f9fa;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 40px;
            text-align: center;
        }
        
        .header h1 {
            color: #ffffff;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #e8eafd;
            font-size: 16px;
            margin: 0;
        }
        
        .content {
            padding: 40px;
        }
        
        .content h2 {
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .content p {
            color: #4a5568;
            font-size: 16px;
            margin-bottom: 16px;
            line-height: 1.7;
        }
        
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        
        .button:hover {
            transform: translateY(-1px);
        }
        
        .info-box {
            background-color: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        
        .info-box h3 {
            color: #2d3748;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .info-box p {
            margin-bottom: 8px;
            color: #4a5568;
        }
        
        .footer {
            background-color: #2d3748;
            padding: 30px 40px;
            text-align: center;
        }
        
        .footer p {
            color: #a0aec0;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .social-links {
            margin: 20px 0;
        }
        
        .social-links a {
            display: inline-block;
            margin: 0 10px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .divider {
            height: 1px;
            background-color: #e2e8f0;
            margin: 30px 0;
        }
        
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                box-shadow: none;
            }
            
            .header, .content, .footer {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .content h2 {
                font-size: 20px;
            }
            
            .button {
                display: block;
                text-align: center;
                margin: 20px 0;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>@yield('company_name', 'Roke Industries')</h1>
            <p>@yield('header_subtitle', 'Hosting Platform Profesional')</p>
        </div>
        
        <div class="content">
            @yield('content')
        </div>
        
        <div class="footer">
            <p><strong>Roke Industries</strong></p>
            <p>Tu plataforma de hosting confiable</p>
            
            <div class="social-links">
                <a href="#">Soporte</a>
                <a href="#">Documentación</a>
                <a href="#">Estado del Servicio</a>
            </div>
            
            <p>© {{ date('Y') }} Roke Industries. Todos los derechos reservados.</p>
            <p>
                <a href="#">Política de Privacidad</a> | 
                <a href="#">Términos de Servicio</a> | 
                <a href="#">Cancelar suscripción</a>
            </p>
        </div>
    </div>
</body>
</html>

