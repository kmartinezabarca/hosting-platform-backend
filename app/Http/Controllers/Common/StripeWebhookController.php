<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Invalid payload in Stripe webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Invalid signature in Stripe webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event['data']['object']);
                break;
            
            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event['data']['object']);
                break;
            
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event['data']['object']);
                break;
            
            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event['data']['object']);
                break;
            
            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($event['data']['object']);
                break;
            
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event['data']['object']);
                break;
            
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event['data']['object']);
                break;
            
            default:
                Log::info('Unhandled Stripe webhook event type: ' . $event['type']);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle successful payment intent
     */
    private function handlePaymentIntentSucceeded($paymentIntent)
    {
        Log::info('Payment Intent succeeded: ' . $paymentIntent['id']);
        
        // Get metadata
        $userId = $paymentIntent['metadata']['user_id'] ?? null;
        $serviceId = $paymentIntent['metadata']['service_id'] ?? null;
        
        if ($userId && $serviceId) {
            // Update service status to active
            // In a real implementation, update the database
            Log::info("Activating service {$serviceId} for user {$userId}");
            
            // Here you would:
            // 1. Update service status in database
            // 2. Provision the actual service (VPS, hosting, etc.)
            // 3. Send confirmation email to user
            // 4. Create invoice record
        }
    }

    /**
     * Handle failed payment intent
     */
    private function handlePaymentIntentFailed($paymentIntent)
    {
        Log::warning('Payment Intent failed: ' . $paymentIntent['id']);
        
        $userId = $paymentIntent['metadata']['user_id'] ?? null;
        $serviceId = $paymentIntent['metadata']['service_id'] ?? null;
        
        if ($userId && $serviceId) {
            // Handle failed payment
            Log::warning("Payment failed for service {$serviceId} for user {$userId}");
            
            // Here you would:
            // 1. Update service status to payment_failed
            // 2. Send notification to user
            // 3. Potentially suspend service if it was active
        }
    }

    /**
     * Handle successful invoice payment (for subscriptions)
     */
    private function handleInvoicePaymentSucceeded($invoice)
    {
        Log::info('Invoice payment succeeded: ' . $invoice['id']);
        
        $subscriptionId = $invoice['subscription'] ?? null;
        
        if ($subscriptionId) {
            // Update subscription status and extend service
            Log::info("Subscription payment succeeded: {$subscriptionId}");
            
            // Here you would:
            // 1. Update subscription record in database
            // 2. Extend service billing period
            // 3. Update service status to active if suspended
            // 4. Send payment confirmation email
        }
    }

    /**
     * Handle failed invoice payment (for subscriptions)
     */
    private function handleInvoicePaymentFailed($invoice)
    {
        Log::warning('Invoice payment failed: ' . $invoice['id']);
        
        $subscriptionId = $invoice['subscription'] ?? null;
        
        if ($subscriptionId) {
            // Handle failed subscription payment
            Log::warning("Subscription payment failed: {$subscriptionId}");
            
            // Here you would:
            // 1. Update subscription status
            // 2. Send payment failure notification
            // 3. Implement retry logic or grace period
            // 4. Suspend service after grace period
        }
    }

    /**
     * Handle subscription creation
     */
    private function handleSubscriptionCreated($subscription)
    {
        Log::info('Subscription created: ' . $subscription['id']);
        
        // Here you would:
        // 1. Create subscription record in database
        // 2. Link to user and service
        // 3. Set up billing schedule
        // 4. Send welcome email
    }

    /**
     * Handle subscription update
     */
    private function handleSubscriptionUpdated($subscription)
    {
        Log::info('Subscription updated: ' . $subscription['id']);
        
        // Here you would:
        // 1. Update subscription record in database
        // 2. Handle plan changes
        // 3. Prorate billing if necessary
        // 4. Update service configuration
    }

    /**
     * Handle subscription deletion/cancellation
     */
    private function handleSubscriptionDeleted($subscription)
    {
        Log::info('Subscription deleted: ' . $subscription['id']);
        
        // Here you would:
        // 1. Update subscription status in database
        // 2. Schedule service termination
        // 3. Send cancellation confirmation
        // 4. Handle data backup/export
    }
}

