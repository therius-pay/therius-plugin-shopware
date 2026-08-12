<?php declare(strict_types=1);

namespace TheriusPayment\Service;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class TheriusPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private SystemConfigService $systemConfigService;
    private RequestStack $requestStack;
    private LoggerInterface $logger;

    private const ZERO_DECIMAL_CURRENCIES = [
        'JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 
        'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'
    ];

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $order = $transaction->getOrder();
        $orderCode = $order->getOrderNumber();
        $transactionId = $transaction->getOrderTransaction()->getId();
        
        $session = $this->requestStack->getSession();
        
        // 1. If we have a pre-order result for this orderCode, use it directly (bypass re-charge)
        $preOrderResult = $session->get('therius_pre_order_result_' . $orderCode);
        if ($preOrderResult) {
            $session->remove('therius_pre_order_result_' . $orderCode);
            
            // Only accept finalized statuses from the pre-order flow
            if (in_array($preOrderResult['status'], ['authorized', 'captured', 'pending'])) {
                // The order is placed and payment outcome was successful in pre-order step
                return new RedirectResponse($transaction->getReturnUrl());
            }
            throw new AsyncPaymentProcessException($transactionId, 'Payment was declined during pre-order phase.');
        }

        // 2. Direct-charge fallback (if JS wasn't used or pre-order didn't happen)
        $nonce = $dataBag->get('therius_nonce');
        if (!$nonce) {
            throw new AsyncPaymentProcessException($transactionId, 'Missing payment nonce.');
        }

        $totalAmount = $transaction->getOrderTransaction()->getAmount()->getTotalPrice();
        $currencyCode = $salesChannelContext->getCurrency()->getIsoCode();
        
        $isZeroDecimal = in_array(strtoupper($currencyCode), self::ZERO_DECIMAL_CURRENCIES, true);
        $amountValue = $isZeroDecimal ? (int)round($totalAmount) : (int)round($totalAmount * 100);
        $exponent = $isZeroDecimal ? 0 : 2;

        $payload = [
            'orderCode' => $orderCode,
            'amount' => [
                'value' => $amountValue,
                'currency' => $currencyCode,
                'exponent' => $exponent
            ],
            // Since we don't know the method, we wrap it in a generic object if it's a raw nonce
            // But usually the JS provides a structured JSON. Let's assume the frontend sends structured JSON.
            // For safety, we just pass the decoded JSON if it's JSON, or raw otherwise.
        ];

        $decodedNonce = json_decode($nonce, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedNonce)) {
            $payload = array_merge($payload, $decodedNonce);
        } else {
            // Fallback for raw token
            $payload['card'] = ['nonceData' => ['nonce' => $nonce]];
        }

        $isTestMode = $this->systemConfigService->get('TheriusPayment.config.testMode');
        $secretKey = $isTestMode 
            ? $this->systemConfigService->get('TheriusPayment.config.testPrivateKey') 
            : $this->systemConfigService->get('TheriusPayment.config.livePrivateKey');
            
        $apiUrl = $isTestMode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        $ch = curl_init($apiUrl . '/v1/payment/purchase');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        // Idempotency: orderId + payload hash
        $idempotencyKey = $transactionId . '-' . md5(json_encode($payload));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
            'Idempotency-Key: ' . $idempotencyKey
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400 || $response === false) {
            $this->logger->error('Therius Payment failed', ['response' => $response, 'httpCode' => $httpCode]);
            throw new AsyncPaymentProcessException($transactionId, 'Payment failed.');
        }

        $responseData = json_decode($response, true);
        if (empty($responseData['paymentCode'])) {
            throw new AsyncPaymentProcessException($transactionId, 'Payment declined or invalid response.');
        }

        $status = $responseData['status'] ?? '';
        if (!in_array($status, ['authorized', 'captured', 'pending'])) {
            throw new AsyncPaymentProcessException($transactionId, 'Payment was declined.');
        }

        return new RedirectResponse($transaction->getReturnUrl());
    }

    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        // For Therius, we don't need a separate finalizing step using the return URL, 
        // as the purchase outcome is known immediately (or pending and handled by webhook).
        // Webhooks will update the Shopware order state correctly.
    }
}
