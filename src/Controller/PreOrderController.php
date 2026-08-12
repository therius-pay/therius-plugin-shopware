<?php declare(strict_types=1);

namespace TheriusPayment\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @RouteScope(scopes={"storefront"})
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PreOrderController extends AbstractController
{
    private SystemConfigService $systemConfigService;
    private CartService $cartService;
    private RequestStack $requestStack;

    private const ZERO_DECIMAL_CURRENCIES = [
        'JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 
        'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'
    ];

    public function __construct(
        SystemConfigService $systemConfigService,
        CartService $cartService,
        RequestStack $requestStack
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->cartService = $cartService;
        $this->requestStack = $requestStack;
    }

    /**
     * @return array{0: string, 1: string} [apiUrl, secretKey]
     */
    private function resolveCredentials(): array
    {
        $isTestMode = $this->systemConfigService->get('TheriusPayment.config.testMode');
        $secretKey = $isTestMode
            ? $this->systemConfigService->get('TheriusPayment.config.testPrivateKey')
            : $this->systemConfigService->get('TheriusPayment.config.livePrivateKey');

        $apiUrl = $isTestMode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        return [$apiUrl, $secretKey];
    }

    /**
     * @return array{value: int, currency: string, exponent: int}
     */
    private function resolveCartAmount(SalesChannelContext $context): array
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $totalAmount = $cart->getPrice()->getTotalPrice();

        $currencyCode = $context->getCurrency()->getIsoCode();
        $isZeroDecimal = in_array(strtoupper($currencyCode), self::ZERO_DECIMAL_CURRENCIES, true);

        return [
            'value' => $isZeroDecimal ? (int)round($totalAmount) : (int)round($totalAmount * 100),
            'currency' => $currencyCode,
            'exponent' => $isZeroDecimal ? 0 : 2,
        ];
    }

    /**
     * Mints a fresh SDK client token on demand, right when the checkout lightbox opens
     * (rather than baking one into the confirm page HTML at page-load time, which goes
     * stale if the shopper lingers or the page is re-rendered by an unrelated AJAX
     * update — see ../../shopware.md item 4 and the follow-up investigation).
     *
     * Passes country/amount/currency so /v1/sdk/session creates a real checkout_sessions
     * row (therius-public-api requires `country` to do this — see sdkSessionRequest in
     * handlers_sdk.go). Without this, the SDK falls back to its no-session
     * _fetchConfigThenRender path: no server-driven field specs (breaks ACH's
     * account/routing-number inputs — CheckoutWidget._apmContent only renders them from
     * serverMethod.fields), no `sessionData().amount/currency` for the widget to filter
     * methods by (all payment methods show regardless of country), and the express
     * wallet button never even attempts to mount (_mountWalletButton bails out with no
     * amount/currency), which orphans its "Or pay another way" divider with nothing above it.
     */
    #[Route(path: '/therius/session-token', name: 'frontend.therius.session_token', defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], methods: ['POST'])]
    public function sessionToken(SalesChannelContext $context): JsonResponse
    {
        [$apiUrl, $secretKey] = $this->resolveCredentials();

        $amount = $this->resolveCartAmount($context);
        $country = $context->getShippingLocation()->getCountry()->getIso();

        $ch = curl_init($apiUrl . '/v1/sdk/session');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'country' => $country,
            'amount' => $amount['value'],
            'currency' => $amount['currency'],
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return new JsonResponse(['error' => 'Could not start checkout session.'], 400);
        }

        $responseData = json_decode($response, true);
        $clientToken = $responseData['clientToken'] ?? '';
        if (!$clientToken) {
            return new JsonResponse(['error' => 'Could not start checkout session.'], 400);
        }

        return new JsonResponse([
            'clientToken' => $clientToken,
            'amount' => $amount['value'],
            'currency' => $amount['currency'],
            'exponent' => $amount['exponent'],
            'country' => $country,
        ]);
    }

    #[Route(path: '/therius/pre-order', name: 'frontend.therius.pre_order', defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], methods: ['POST'])]
    public function preOrderPurchase(Request $request, SalesChannelContext $context): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        // is_array() guard (not just truthy): a JSON body that decodes to a scalar
        // (e.g. the frontend accidentally posting a bare string) previously reached
        // array_merge() below and crashed with an uncaught TypeError — Symfony's
        // HTML error page for that then fails client-side JSON.parse() with a
        // confusing "Unexpected token '<'" instead of a clean error.
        if (!$payload || !is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $amount = $this->resolveCartAmount($context);

        // Use cart token as pre-order identifier
        $orderCode = $this->cartService->getCart($context->getToken(), $context)->getToken();

        // Ensure we save the orderCode to session unconditionally so the finalize/resume step can verify it
        // Lesson 16: "Set any session/state value the next request will need to verify against, unconditionally, before the branch that might early-return without it."
        $session = $this->requestStack->getSession();
        $session->set('therius_pre_order_code', $orderCode);

        $customer = $context->getCustomer();

        // Required for card payments — CyberSource (and others) hard-decline with
        // MISSING_FIELD without at least the billing state/region. Pull it from
        // Shopware's own customer record rather than asking the shopper to
        // re-enter it in the widget.
        if (isset($payload['card']['nonceData']) && is_array($payload['card']['nonceData'])
            && empty($payload['card']['nonceData']['cardAddress']) && $customer
        ) {
            $billing = $customer->getActiveBillingAddress();
            if ($billing) {
                $payload['card']['nonceData']['cardAddress'] = [
                    'address1' => $billing->getStreet(),
                    'city' => $billing->getCity(),
                    'state' => $billing->getCountryState()?->getShortCode(),
                    'countryCode' => $billing->getCountry()?->getIso(),
                    'postalCode' => $billing->getZipcode(),
                ];
            }
        }

        if (empty($payload['shopper']) && $customer) {
            // shopper.id is required server-side whenever tokenize:true is set
            // (vault-consent "save my card"/"save this bank account").
            $payload['shopper'] = [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'name' => trim($customer->getFirstName() . ' ' . $customer->getLastName()),
            ];
        }

        // Build purchase payload
        // The frontend sends the card/apm/walletData wrapper structure which we merge
        $purchasePayload = array_merge($payload, [
            'orderCode' => $orderCode,
            'amount' => $amount,
        ]);

        [$apiUrl, $secretKey] = $this->resolveCredentials();

        $ch = curl_init($apiUrl . '/v1/payment/purchase');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($purchasePayload));
        
        $idempotencyKey = 'pre-' . $orderCode . '-' . md5(json_encode($purchasePayload));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
            'Idempotency-Key: ' . $idempotencyKey
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            return new JsonResponse(['error' => 'Payment failed.'], 400);
        }

        $responseData = json_decode($response, true);
        
        if (empty($responseData['paymentCode'])) {
            return new JsonResponse(['error' => 'Payment declined.'], 400);
        }

        // Lesson 15: Gate on actionRequired presence, not specific status strings
        if (!empty($responseData['actionRequired'])) {
            return new JsonResponse(['actionRequired' => $responseData['actionRequired']]);
        }

        $status = $responseData['status'] ?? '';
        if (in_array($status, ['authorized', 'captured', 'pending'])) {
            // Stash final outcome keyed by orderCode so TheriusPaymentHandler can pick it up
            $session->set('therius_pre_order_result_' . $orderCode, $responseData);
            return new JsonResponse(['success' => true]);
        }

        return new JsonResponse(['error' => 'Payment declined.'], 400);
    }

    #[Route(path: '/therius/pre-order-inquiry', name: 'frontend.therius.pre_order_inquiry', defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], methods: ['POST'])]
    public function preOrderInquiry(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $paymentCode = $payload['paymentCode'] ?? null;
        if (!$paymentCode) {
            return new JsonResponse(['error' => 'Missing payment code'], 400);
        }

        $session = $this->requestStack->getSession();
        $expectedOrderCode = $session->get('therius_pre_order_code');

        if (!$expectedOrderCode) {
            return new JsonResponse(['error' => 'No active pre-order session'], 400);
        }

        [$apiUrl, $secretKey] = $this->resolveCredentials();

        // Lesson 13: retry inquiry to absorb read-after-write gap
        $attempts = 3;
        $responseData = null;

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($apiUrl . '/v1/payment/inquiry/' . urlencode($paymentCode));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $secretKey
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $responseData = json_decode($response, true);
                if (isset($responseData['status']) && !in_array($responseData['status'], ['pending_ddc', 'pending_3ds'])) {
                    // Final status reached
                    break;
                }
            }
            usleep(700000); // 0.7s
        }

        if (!$responseData) {
            return new JsonResponse(['error' => 'Could not verify payment outcome.'], 400);
        }

        if (($responseData['orderCode'] ?? '') !== $expectedOrderCode) {
            return new JsonResponse(['error' => 'Order code mismatch.'], 400);
        }

        $status = $responseData['status'] ?? '';
        if (in_array($status, ['authorized', 'captured', 'pending'])) {
            $session->set('therius_pre_order_result_' . $expectedOrderCode, $responseData);
            return new JsonResponse(['success' => true]);
        }

        return new JsonResponse(['error' => 'Payment declined.'], 400);
    }
}
