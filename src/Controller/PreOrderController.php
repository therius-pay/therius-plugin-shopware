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
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PreOrderController extends AbstractController
{
    private SystemConfigService $systemConfigService;
    private CartService $cartService;
    private RequestStack $requestStack;
    private NumberRangeValueGeneratorInterface $numberRangeValueGenerator;

    private const ZERO_DECIMAL_CURRENCIES = [
        'JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF',
        'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'
    ];

    public function __construct(
        SystemConfigService $systemConfigService,
        CartService $cartService,
        RequestStack $requestStack,
        NumberRangeValueGeneratorInterface $numberRangeValueGenerator
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->cartService = $cartService;
        $this->requestStack = $requestStack;
        $this->numberRangeValueGenerator = $numberRangeValueGenerator;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [apiUrl, secretKey, merchantCode]
     */
    private function resolveCredentials(): array
    {
        $isTestMode = $this->systemConfigService->get('TheriusPayment.config.testMode');
        $secretKey = $isTestMode
            ? $this->systemConfigService->get('TheriusPayment.config.testPrivateKey')
            : $this->systemConfigService->get('TheriusPayment.config.livePrivateKey');
        $merchantCode = $isTestMode
            ? $this->systemConfigService->get('TheriusPayment.config.testMerchantCode')
            : $this->systemConfigService->get('TheriusPayment.config.liveMerchantCode');

        $apiUrl = $isTestMode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        return [$apiUrl, $secretKey, $merchantCode];
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

        // Catch a stale/emptied cart locally instead of letting the remote API reject it
        // with the opaque "Amount must be a positive integer greater than zero" — that
        // message gives the shopper no indication the fix is to refresh, and it's
        // reachable any time the lightbox outlives the cart it was opened for (item
        // removed in another tab, session/cart expired while the widget sat open, etc).
        if ($amount['value'] <= 0) {
            return new JsonResponse(['error' => 'Your cart appears to be empty or your session has expired. Please refresh the page and try again.'], 400);
        }

        // Cart token is stable for the whole checkout session (doesn't change between
        // retries), so it's what TheriusPaymentHandler::pay() can also derive later
        // (via SalesChannelContext::getToken()) to find this attempt's stashed result —
        // NOT Shopware's order number, which doesn't exist yet at pre-order time.
        $cartToken = $context->getToken();
        $session = $this->requestStack->getSession();

        // Reserve Shopware's own order number up front and reuse it as the Therius
        // orderCode's prefix, so a merchant looking at a Therius payment and the
        // corresponding Shopware order can tell at a glance they're the same purchase.
        // Reserved once per cart (not per attempt) and stashed in session so it survives
        // to TheriusPaymentHandler::validate() later, which attaches it to the Cart via
        // OrderConverter::ORIGINAL_ORDER_NUMBER — Shopware's own documented extension
        // point for forcing cart-to-order conversion to use a specific number instead of
        // generating its own (OrderConverter.php:221-225). Reusing rather than reserving
        // fresh per attempt avoids burning a Shopware order number for every declined
        // retry on the same cart — a real, if minor, tradeoff: an abandoned checkout
        // (shopper never completes any attempt) still burns the one number reserved here.
        $orderNumberSessionKey = 'therius_reserved_order_number_' . $cartToken;
        $reservedOrderNumber = $session->get($orderNumberSessionKey);
        if (!$reservedOrderNumber) {
            $reservedOrderNumber = $this->numberRangeValueGenerator->getValue(
                'order',
                $context->getContext(),
                $context->getSalesChannelId()
            );
            $session->set($orderNumberSessionKey, $reservedOrderNumber);
        }

        // orderCode is the bare reserved Shopware order number — exact match, no suffix —
        // so it's directly recognizable against the Shopware admin order list.
        //
        // paymentCode carries the uniqueness burden instead: therius-public-api's
        // duplicate check (theriuscore.Lookup) requires BOTH order_code AND payment_code
        // to match an existing row (WHERE order_code=$1 AND payment_code=$2), so a
        // constant orderCode is safe from "Payment already exists within the order" as
        // long as paymentCode changes per attempt — same fix as before, just moved to a
        // different field now that orderCode itself needs to stay exact.
        //
        // EXCEPTION: a pending_ddc 3DS continuation (payload carries `threeDsSetup`, set
        // by the SDK's retry() closure after device-data-collection finishes) must reuse
        // the SAME paymentCode as the attempt that returned pending_ddc, since it's
        // resuming that same logical attempt, not starting a new one — per
        // handlers_payment.go: "DDC happens before any payment row exists, so the caller
        // simply retries the original purchase/authorization call with
        // threeDsSetup.sessionId set".
        $orderCode = $reservedOrderNumber;
        if (!empty($payload['threeDsSetup']) && $session->has('therius_pre_order_payment_code')) {
            $paymentCode = $session->get('therius_pre_order_payment_code');
        } else {
            $paymentCode = $reservedOrderNumber . '-' . bin2hex(random_bytes(4));
        }

        // Ensure we save these to session unconditionally so the finalize/resume step can verify against them
        // Lesson 16: "Set any session/state value the next request will need to verify against, unconditionally, before the branch that might early-return without it."
        $session->set('therius_pre_order_code', $orderCode);
        $session->set('therius_pre_order_payment_code', $paymentCode);

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
                // Shopware's CountryState::getShortCode() returns its own ISO-3166-2-style
                // code prefixed with the country (e.g. "US-NY", "BR-SP"), not the bare
                // subdivision code gateways expect. CyberSource hard-declines
                // orderInformation.billTo.administrativeArea with INVALID_DATA when it
                // receives "US-NY" instead of "NY" — strip the country prefix.
                $stateShortCode = $billing->getCountryState()?->getShortCode();
                $state = $stateShortCode !== null && str_contains($stateShortCode, '-')
                    ? substr($stateShortCode, strpos($stateShortCode, '-') + 1)
                    : $stateShortCode;

                $payload['card']['nonceData']['cardAddress'] = [
                    'address1' => $billing->getStreet(),
                    'city' => $billing->getCity(),
                    'state' => $state,
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

        // Required for Stripe ACH's mandate_data.customer_acceptance.online.user_agent —
        // Stripe hard-rejects an empty user_agent rather than silently accepting it.
        // Also feeds 3DS browser_info. Must be Shopware's own request (the real shopper's
        // browser), not something the SDK/gateway can fill in on its own. Same fix already
        // applied in therius-plugin-woocommerce/includes/class-wc-gateway-therius.php after
        // every WooCommerce-originated ACH purchase failed with the same missing-user_agent
        // error — this plugin never had the block at all.
        if (empty($payload['browserInfo'])) {
            $payload['browserInfo'] = [
                'userAgent' => $request->headers->get('User-Agent', ''),
                'ipAddress' => $request->getClientIp(),
            ];
        }

        [$apiUrl, $secretKey, $merchantCode] = $this->resolveCredentials();

        // Build purchase payload
        // The frontend sends the card/apm/walletData wrapper structure which we merge.
        // /v1/payment/purchase authenticates the merchant from the JSON body's `key` +
        // `merchantCode` fields for server-to-server calls (unlike /v1/sdk/session, which
        // reads only the private key from the Authorization header) — see
        // paymentRequest.Key/MerchantCode and getMerchant()'s `m.code = $3` filter in
        // therius-public-api/auth.go. Without both, every call 401s with "Merchant does
        // not exist or incorrect credentials", regardless of payment method.
        $purchasePayload = array_merge($payload, [
            'key' => $secretKey,
            'merchantCode' => $merchantCode,
            'orderCode' => $orderCode,
            // Must be explicit and distinct from orderCode here — see the orderCode/
            // paymentCode comment above this block for why.
            'paymentCode' => $paymentCode,
            'amount' => $amount,
        ]);

        $ch = curl_init($apiUrl . '/v1/payment/purchase');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($purchasePayload));
        
        $idempotencyKey = 'pre-' . $paymentCode . '-' . md5(json_encode($purchasePayload));

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

        // Lesson 15: Gate on actionRequired presence, not specific status strings — and
        // check it BEFORE the paymentCode-empty check below. A pending_ddc response has
        // no top-level `paymentCode` at all (only a nested one inside `actionRequired`,
        // which is actually the DDC session id, not a real payment identifier — no
        // payment row exists yet at this stage). Checking paymentCode-empty first meant
        // every pending_ddc response — i.e. every card needing 3DS device-data-collection
        // — was misreported as "Payment declined." before ever reaching this branch,
        // which is the real explanation for cards silently never completing the
        // fingerprint/DDC step all session, while ACH (no DDC needed, goes straight to a
        // final status with a real top-level paymentCode) worked fine throughout.
        if (!empty($responseData['actionRequired'])) {
            return new JsonResponse(['actionRequired' => $responseData['actionRequired']]);
        }

        if (empty($responseData['paymentCode'])) {
            return $this->declineResponse($responseData);
        }

        $status = $responseData['status'] ?? '';
        if (in_array($status, ['authorized', 'captured', 'pending'])) {
            // Stash final outcome keyed by cart token — TheriusPaymentHandler::pay() looks
            // it up the same way, via SalesChannelContext::getToken(), not by orderCode
            // (which pay() has no way to reconstruct — it only knows Shopware's order
            // number, assigned after this point) and not by Shopware's order number
            // (doesn't exist yet here).
            $session->set('therius_pre_order_result_' . $cartToken, $responseData);
            return new JsonResponse(['success' => true]);
        }

        return $this->declineResponse($responseData);
    }

    /**
     * Surfaces the API's `refusalCode.reason`/`recoveryAction` instead of a flat
     * hardcoded "Payment declined." — `therius-payment.plugin.js` reads
     * `recoveryAction` to reject its onNonce/onApm promise with the SDK's
     * `DeclineError` (rather than a plain Error), which is what lets
     * CheckoutWidget's built-in smart recovery (auto-remount for a retryable
     * decline, a "try a different method" nudge, or a terminal failure state)
     * actually activate — see therius-public-api's recoveryActionForCode
     * (services_recovery.go) for how recoveryAction is derived server-side, and
     * therius-sdk's decline.ts for the DeclineError contract this feeds.
     *
     * @param array<string, mixed> $responseData
     */
    private function declineResponse(array $responseData): JsonResponse
    {
        $refusalCode = is_array($responseData['refusalCode'] ?? null) ? $responseData['refusalCode'] : [];
        $reason = $refusalCode['reason'] ?? $responseData['reason'] ?? 'Payment declined.';
        $recoveryAction = $refusalCode['recoveryAction'] ?? 'switch_method';

        return new JsonResponse(['error' => $reason, 'recoveryAction' => $recoveryAction], 400);
    }

    #[Route(path: '/therius/pre-order-inquiry', name: 'frontend.therius.pre_order_inquiry', defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], methods: ['POST'])]
    public function preOrderInquiry(Request $request, SalesChannelContext $context): JsonResponse
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
            // Keyed by cart token, same as preOrderPurchase()'s success branch — see the
            // comment there for why (matches what TheriusPaymentHandler::pay() looks up).
            $session->set('therius_pre_order_result_' . $context->getToken(), $responseData);
            return new JsonResponse(['success' => true]);
        }

        return $this->declineResponse($responseData);
    }
}
