<?php declare(strict_types=1);

namespace TheriusPayment\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * WebhookController
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController extends AbstractController
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private EntityRepository $orderTransactionRepository;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $orderTransactionRepository
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    #[Route(path: '/therius/webhook', name: 'frontend.therius.webhook', defaults: ['csrf_protected' => false], methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        $signatureHeader = $request->headers->get('x-therius-signature');
        if (!$signatureHeader || strpos($signatureHeader, 'sha256=') !== 0) {
            return new Response('Missing or malformed signature', Response::HTTP_UNAUTHORIZED);
        }

        $environment = $payload['environment'] ?? 'sandbox';
        
        $webhookSecret = ($environment === 'sandbox')
            ? $this->systemConfigService->get('TheriusPayment.config.testWebhookSecret')
            : $this->systemConfigService->get('TheriusPayment.config.liveWebhookSecret');

        if (!$webhookSecret) {
            return new Response('Webhook secret not configured', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signatureValue = substr($signatureHeader, 7);
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $webhookSecret);
        if (!hash_equals($expectedSignature, $signatureValue)) {
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $type = $payload['type'] ?? '';
        $paymentCode = $payload['data']['paymentCode'] ?? '';
        $orderCode = $payload['data']['orderCode'] ?? '';

        if (!$paymentCode && !$orderCode) {
            return new Response('OK'); // Cannot process without identifiers
        }

        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        
        // We look up by order number (which we passed as orderCode)
        // In Shopware, OrderTransaction doesn't store order number directly easily without join, 
        // but we can query by order.orderNumber.
        $criteria->addAssociation('order');
        if ($orderCode) {
            $criteria->addFilter(new EqualsFilter('order.orderNumber', $orderCode));
        }
        
        $transactions = $this->orderTransactionRepository->search($criteria, $context);
        $transaction = $transactions->first();

        if (!$transaction) {
            $this->logger->warning('Webhook received for unknown order', ['orderCode' => $orderCode]);
            return new Response('OK'); // Acknowledge so Therius doesn't retry infinitely for unknown orders
        }

        $transactionId = $transaction->getId();

        try {
            switch ($type) {
                case 'payment.captured':
                    $this->transactionStateHandler->paid($transactionId, $context);
                    break;
                case 'payment.refused':
                case 'payment.cancelled':
                case 'payment.chargeback':
                case 'payment.capture_failed':
                    $this->transactionStateHandler->fail($transactionId, $context);
                    break;
                case 'payment.refunded':
                    $this->transactionStateHandler->refund($transactionId, $context);
                    break;
                case 'payment.authorized':
                case 'payment.refund_failed':
                case 'payment.cancel_failed':
                    // No-op or log
                    break;
                default:
                    $this->logger->info('Unknown webhook type', ['type' => $type]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error processing webhook', ['error' => $e->getMessage()]);
            // Still return 200 to prevent retries if it's a state machine error (e.g. already paid)
        }

        return new Response('OK');
    }
}
