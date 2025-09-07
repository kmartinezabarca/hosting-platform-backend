<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Support\Facades\Mail;

class TestEmailSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test {type?} {--email=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the email system with different email types';

    protected $emailService;

    public function __construct(EmailService $emailService)
    {
        parent::__construct();
        $this->emailService = $emailService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->argument('type');
        $email = $this->option('email') ?: 'test@rokeindustries.com';

        // Create or get test user
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Usuario de Prueba',
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ]
        );

        if (!$type) {
            $type = $this->choice(
                'Selecciona el tipo de correo a probar:',
                [
                    'welcome' => 'Correo de Bienvenida',
                    'password-reset' => 'Restablecimiento de Contraseña',
                    'purchase' => 'Confirmación de Compra',
                    'payment' => 'Pago Exitoso',
                    'invoice' => 'Factura Generada',
                    'service' => 'Notificación de Servicio',
                    'account' => 'Actualización de Cuenta',
                    'all' => 'Todos los correos',
                ]
            );
        }

        $this->info("Enviando correo(s) de prueba a: {$email}");
        $this->info("Configuración actual:");
        $this->info("- Mailer: " . config('mail.default'));
        $this->info("- Host: " . config('mail.mailers.sendgrid.host'));
        $this->info("- From: " . config('mail.from.address'));

        try {
            switch ($type) {
                case 'welcome':
                    $this->testWelcomeEmail($user);
                    break;
                case 'password-reset':
                    $this->testPasswordResetEmail($user);
                    break;
                case 'purchase':
                    $this->testPurchaseEmail($user);
                    break;
                case 'payment':
                    $this->testPaymentEmail($user);
                    break;
                case 'invoice':
                    $this->testInvoiceEmail($user);
                    break;
                case 'service':
                    $this->testServiceEmail($user);
                    break;
                case 'account':
                    $this->testAccountEmail($user);
                    break;
                case 'all':
                    $this->testAllEmails($user);
                    break;
                default:
                    $this->error("Tipo de correo no válido: {$type}");
                    return 1;
            }

            $this->info("✅ Correo(s) enviado(s) exitosamente!");
            $this->info("Revisa tu bandeja de entrada en: {$email}");

        } catch (\Exception $e) {
            $this->error("❌ Error al enviar correo: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function testWelcomeEmail(User $user)
    {
        $this->line("Enviando correo de bienvenida...");
        $this->emailService->sendWelcomeEmail($user, url('/login'));
    }

    private function testPasswordResetEmail(User $user)
    {
        $this->line("Enviando correo de restablecimiento de contraseña...");
        $resetUrl = url('/reset-password?token=test_token_123');
        $this->emailService->sendPasswordResetEmail($user, $resetUrl, '192.168.1.1');
    }

    private function testPurchaseEmail(User $user)
    {
        $this->line("Enviando correo de confirmación de compra...");
        $order = (object) [
            'id' => 'ORD-2024-001',
            'created_at' => now(),
            'transaction_id' => 'TXN-123456789'
        ];
        $items = [
            ['name' => 'Plan Profesional', 'description' => 'Hosting profesional mensual', 'price' => 29.99, 'quantity' => 1]
        ];
        $this->emailService->sendPurchaseConfirmationEmail($user, $order, $items, 29.99, 'Tarjeta de crédito');
    }

    private function testPaymentEmail(User $user)
    {
        $this->line("Enviando correo de pago exitoso...");
        $payment = (object) [
            'amount' => 29.99,
            'created_at' => now(),
            'method' => 'Tarjeta de crédito',
            'transaction_id' => 'PAY-123456789'
        ];
        $subscription = (object) [
            'plan_name' => 'Plan Profesional',
            'billing_cycle' => 'Mensual',
            'next_billing_date' => now()->addMonth()
        ];
        $this->emailService->sendPaymentSuccessEmail($user, $payment, $subscription, [], null, true);
    }

    private function testInvoiceEmail(User $user)
    {
        $this->line("Enviando correo de factura generada...");
        $invoice = (object) [
            'number' => 'INV-2024-001',
            'created_at' => now(),
            'due_date' => now()->addDays(30),
            'total' => 29.99,
            'status' => 'paid'
        ];
        $invoiceItems = [
            ['description' => 'Plan Profesional - Enero 2024', 'period' => '01/01/2024 - 31/01/2024', 'amount' => 29.99]
        ];
        $this->emailService->sendInvoiceGeneratedEmail($user, $invoice, $invoiceItems, url('/invoices/1/download'));
    }

    private function testServiceEmail(User $user)
    {
        $this->line("Enviando notificación de servicio...");
        $this->emailService->sendServiceNotificationEmail(
            $user,
            'maintenance',
            'Mantenimiento programado en nuestros servidores',
            false,
            null,
            null,
            [
                'maintenanceDate' => now()->addDays(3)->format('d/m/Y H:i'),
                'maintenanceDuration' => '2 horas',
                'affectedServices' => 'Todos los servicios de hosting'
            ]
        );
    }

    private function testAccountEmail(User $user)
    {
        $this->line("Enviando correo de actualización de cuenta...");
        $this->emailService->sendAccountUpdateEmail(
            $user,
            'profile',
            now(),
            '192.168.1.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            false,
            null,
            null,
            ['name' => 'Nuevo Nombre', 'email' => $user->email]
        );
    }

    private function testAllEmails(User $user)
    {
        $this->info("Enviando todos los tipos de correo...");
        $this->testWelcomeEmail($user);
        sleep(1);
        $this->testPasswordResetEmail($user);
        sleep(1);
        $this->testPurchaseEmail($user);
        sleep(1);
        $this->testPaymentEmail($user);
        sleep(1);
        $this->testInvoiceEmail($user);
        sleep(1);
        $this->testServiceEmail($user);
        sleep(1);
        $this->testAccountEmail($user);
    }
}
