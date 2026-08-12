<?php declare(strict_types=1);

namespace TheriusPayment\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Rewritten 2026-08-12 against Shopware's current AbstractPaymentHandler API — the
 * previous version implemented AsynchronousPaymentHandlerInterface, which was removed
 * from Shopware core entirely (not just deprecated) by this instance's version
 * (6.7.2.2). That class fatally failed to load the moment Shopware actually tried to
 * instantiate it — which only happens at real order-placement time, not on plugin
 * install/activate/cache-clear — so PaymentHandlerRegistry::getPaymentMethodHandler()
 * silently returned null for Therius specifically, surfacing to the shopper as the
 * generic Shopware core message "The selected payment method does not exist." with
 * no order ever created. See ../../../../../shopware.md for the incident history.
 */
class TheriusPaymentHandler extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private RequestStack $requestStack;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        RequestStack $requestStack
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->requestStack = $requestStack;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // Refunds and recurring (subscription) charges go through Therius's own
        // dashboard/API, not Shopware's checkout payment-handler flow.
        return false;
    }

    /**
     * Called before the order is persisted. PreOrderController's AJAX step already ran
     * the actual charge and stashed its outcome in session, keyed by cart token (the
     * only identifier both this method and PreOrderController can independently derive
     * — Shopware hasn't assigned an order number yet at pre-order time, so that was
     * never usable as the key). Returning it here as a Struct carries it forward into
     * pay() via the $validateStruct argument, matching how Shopware's own
     * AppPaymentHandler::validate()/pay() pair is designed to be used.
     */
    public function validate(Cart $cart, RequestDataBag $dataBag, SalesChannelContext $context): ?Struct
    {
        $session = $this->requestStack->getSession();
        $cartToken = $context->getToken();

        $preOrderResult = $session->get('therius_pre_order_result_' . $cartToken);
        if (!$preOrderResult) {
            throw PaymentException::validatePreparedPaymentInterrupted(
                'No completed Therius payment found for this cart. Please try again.'
            );
        }
        $session->remove('therius_pre_order_result_' . $cartToken);

        if (!in_array($preOrderResult['status'] ?? '', ['authorized', 'captured', 'pending'], true)) {
            throw PaymentException::validatePreparedPaymentInterrupted('Payment was declined during pre-order phase.');
        }

        // Force the order Shopware is about to create to use the SAME number
        // PreOrderController reserved and used as the Therius orderCode's prefix — this
        // is Shopware's own documented extension point for it (OrderConverter checks for
        // this exact Cart extension before falling back to generating its own number via
        // NumberRangeValueGeneratorInterface — see OrderConverter.php:221-225).
        $orderNumberSessionKey = 'therius_reserved_order_number_' . $cartToken;
        $reservedOrderNumber = $session->get($orderNumberSessionKey);
        if ($reservedOrderNumber) {
            $cart->addExtension(OrderConverter::ORIGINAL_ORDER_NUMBER, new IdStruct($reservedOrderNumber));
            $session->remove($orderNumberSessionKey);
        }

        return new ArrayStruct($preOrderResult);
    }

    /**
     * By the time this runs, validate() has already confirmed the pre-order charge
     * succeeded (or thrown, which stops the order from being created at all) — this
     * only needs to reflect that outcome onto the newly-created order transaction.
     * No redirect is needed since Therius's own checkout lightbox already completed
     * entirely before Shopware's native form submit that leads here.
     */
    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $status = $validateStruct instanceof ArrayStruct ? ($validateStruct->get('status') ?? '') : '';
        $transactionId = $transaction->getOrderTransactionId();

        match ($status) {
            'captured' => $this->transactionStateHandler->paid($transactionId, $context),
            'authorized' => $this->transactionStateHandler->authorize($transactionId, $context),
            // 'pending' (e.g. ACH mandate/microdeposit): leave as in-progress —
            // the webhook receiver (WebhookController) advances it once Therius's
            // async settlement completes.
            default => $this->transactionStateHandler->process($transactionId, $context),
        };

        return null;
    }
}
