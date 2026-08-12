# Therius Payment Plugin for Shopware

The Therius Payment Plugin integrates the Therius Payment Orchestration Platform into Shopware 6. It replaces standard single-gateway integrations with our unified Checkout Widget, supporting cards and local alternative payment methods with dynamic smart routing and centralized reconciliation.

## Installation

1. Download the latest release `.zip`.
2. In your Shopware admin panel, navigate to **Extensions > My extensions**.
3. Click **Upload extension** and upload the `.zip` file.
4. Once uploaded, click **Install** and then **Activate** on the Therius extension.

## Configuration & Environments (Sandbox / Production)

The plugin supports Sandbox and Production side by side — both sets of credentials are stored
at once, and the **Enable Test Mode** switch picks which one is actually used at checkout.

1. Navigate to **Settings > Extensions > Therius Payment**.
2. Fill in, per environment:
   - **Test**: Test Publishable Key, Test Private Key, Test Checkout Config ID, Test Webhook Secret.
   - **Live**: Live Publishable Key, Live Private Key, Live Checkout Config ID, Live Webhook Secret.
3. Toggle **Enable Test Mode** — Enabled routes checkout to `https://api-sandbox.therius.io`
   using the Test credentials; Disabled routes to `https://api.therius.io` using the Live
   credentials.
4. Save.

## Checkout Behavior

Selecting Therius Payments on the confirm page does not render an inline card form. Clicking
**Complete order** with Therius selected opens the Therius Checkout Widget in a lightbox,
fetching a fresh checkout session at that moment (never a token cached from an earlier page
load). Any additional interaction the payment needs — a 3D Secure challenge, an alternative
payment method's redirect, a microdeposit-verification step — happens inside that same
lightbox before the order is created; Shopware only creates the order once payment has
already succeeded.

## Webhook Setup

To ensure payments that require asynchronous validation (like 3D Secure or alternative methods) are correctly updated in Shopware, you **must** configure webhooks.

1. Go to your Therius Dashboard > Developers > Webhooks. Configure one endpoint in **Sandbox**
   mode and one in **Production** mode if you use both.
2. Add a new endpoint pointing to: `https://your-store.com/therius/webhook`
3. Subscribe to the following events:
   - `payment.authorized`
   - `payment.captured`
   - `payment.refused`
   - `payment.refunded`
   - `payment.refund_failed`
   - `payment.cancelled`
   - `payment.chargeback`
   - `payment.capture_failed`
   - `payment.cancel_failed`
4. Copy the generated **Signing Secret** into the matching **Test Webhook Secret** / **Live
   Webhook Secret** field in the Shopware plugin configuration. Every incoming call is verified
   against it (`X-Therius-Signature`, HMAC-SHA256) before anything is applied — unsigned or
   mis-signed requests are rejected with `401`. If you skip this step, orders will remain
   pending and refunds made via the Therius Dashboard will not reflect in Shopware.

## Known Limitations

- **Webhook order matching is currently broken.** `WebhookController` looks up the Shopware
  order by matching its `order.orderNumber` against the webhook payload's `orderCode` — but the
  `orderCode` this plugin actually sends to Therius at charge time is the Shopware **cart
  token** (`PreOrderController::preOrderPurchase`), not the eventual order number, which Shopware
  only assigns once the order is placed (after the charge already happened). These will never
  match, so today a webhook delivery for a real order logs "Webhook received for unknown order"
  and does nothing — captures/refunds/chargebacks/cancellations from the Therius Dashboard do
  not currently update the Shopware order status, regardless of whether the webhook is
  configured correctly. The payload's `paymentCode` is read but not currently used as a lookup
  key either. Fixing this needs the real Therius `paymentCode` persisted onto the order
  transaction at `TheriusPaymentHandler::pay()` time and used as the primary webhook lookup key
  (mirrors the pattern in `therius-plugin-woocommerce` and `therius-plugin-prestashop`, which
  store the payment id/code on the order for exactly this reason). Not yet fixed.
