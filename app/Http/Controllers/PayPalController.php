<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;


class PayPalController extends Controller
{
    private $apiContext;

    public function __construct()
    {
        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                config('services.paypal.clientID'),
                config('services.paypal.clientSecret')
            )
        );

        $this->apiContext->setConfig([
            'mode' =>config('services.paypal.paypalMode'),
        ]);
    }

    public function createPayment(Request $request)
    {
        try {
            // Validate request input
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
            ]);

            $payer = new Payer();
            $payer->setPaymentMethod('paypal');

            $amount = new Amount();
            $amount->setTotal($request->input('amount'));
            $amount->setCurrency('USD');

            $transaction = new Transaction();
            $transaction->setAmount($amount);
            $transaction->setDescription('Payment description');

            $redirectUrls = new RedirectUrls();
            $redirectUrls->setReturnUrl(url('/api/execute-payment'))
                         ->setCancelUrl(url('/api/cancel'));

            $payment = new Payment();
            $payment->setIntent('sale')
                    ->setPayer($payer)
                    ->setTransactions([$transaction])
                    ->setRedirectUrls($redirectUrls);

            Log::info('Creating payment with the following details:', [
                'intent' => $payment->getIntent(),
                'payer' => [
                    'payment_method' => $payer->getPaymentMethod()
                ],
                'transactions' => array_map(function ($transaction) {
                    return [
                        'amount' => $transaction->getAmount()->getTotal(),
                        'currency' => $transaction->getAmount()->getCurrency(),
                        'description' => $transaction->getDescription()
                    ];
                }, $payment->getTransactions()),
                'redirect_urls' => [
                    'return_url' => $redirectUrls->getReturnUrl(),
                    'cancel_url' => $redirectUrls->getCancelUrl()
                ]
            ]);

            // Output apiContext for debugging
            Log::info('API Context:', (array) $this->apiContext);

            // Create payment
            $payment->create($this->apiContext);

            return response()->json(['id' => $payment->getId()]);
        } catch (\Exception $ex) {
            Log::error('Error creating PayPal payment: ' . $ex->getMessage());
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }


    public function executePayment(Request $request)
    {
        $paymentId = $request->input('paymentID');
        $payerId = $request->input('payerID');

        $payment = Payment::get($paymentId, $this->apiContext);

        $execution = new PaymentExecution();
        $execution->setPayerId($payerId);

        try {
            $result = $payment->execute($execution, $this->apiContext);
            return response()->json($result);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }
   
}
