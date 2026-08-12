import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Therius no longer mounts the checkout widget inline on the confirm page.
 * That depended on the page having just been (re)rendered with Therius as
 * the SalesChannelContext's active payment method, and on a clientToken
 * baked into the HTML at that render — both went stale under normal
 * checkout interaction (shipping-method changes, payment-method switches
 * that don't do a full reload, or simply lingering on the page). See
 * ../../../../../../../shopware.md for the incident history.
 *
 * Instead: the widget mounts on demand, inside a lightbox this plugin
 * builds, the moment the shopper submits the confirm form with Therius
 * selected — with a freshly-fetched clientToken every time. 3DS challenges
 * and APM redirects/vouchers are handled entirely inside the SDK itself
 * (CheckoutWidget's own onNonce/onApm/onWalletToken -> _handleAction path),
 * independent of where the widget is mounted.
 */
export default class TheriusPaymentPlugin extends Plugin {
    init() {
        this.config = JSON.parse(this.el.dataset.theriusConfig || '{}');
        this.client = new HttpClient();
        this.checkoutForm = document.getElementById('confirmOrderForm');

        if (!this.checkoutForm || !this.config.paymentMethodId) {
            return;
        }

        // Shopware re-runs PluginManager.initializePlugins() after some AJAX
        // confirm-page updates; guard against binding the submit listener twice
        // on the same form.
        if (this.checkoutForm.theriusBound) {
            return;
        }
        this.checkoutForm.theriusBound = true;

        this.submitButton = this.checkoutForm.querySelector('button[type="submit"]');
        this.lightboxOpen = false;

        this.checkoutForm.addEventListener('submit', this.onSubmit.bind(this));
    }

    onSubmit(e) {
        const selected = document.querySelector('input[name="paymentMethodId"]:checked');
        if (!selected || selected.value !== this.config.paymentMethodId) {
            // Not Therius — let Shopware submit natively.
            return;
        }

        e.preventDefault();

        if (this.lightboxOpen) {
            return;
        }

        this.openLightbox();
    }

    async openLightbox() {
        this.lightboxOpen = true;
        if (this.submitButton) {
            this.submitButton.disabled = true;
        }

        this.buildLightboxDom();

        try {
            if (!window.TheriusSDK) {
                await this.loadScript(this.config.sdkUrl);
            }

            const tokenRes = await this.postJson(this.config.sessionTokenUrl, '{}');
            if (!tokenRes.clientToken) {
                throw new Error(tokenRes.error || 'Could not start checkout.');
            }

            // UMD global is a namespace object ({ TheriusSDK, HostedFields, ... }),
            // not the class itself — see therius-plugin-woocommerce's
            // assets/js/therius-checkout.js, the reference usage. The constructor
            // is synchronous; there is no static .init().
            const sdk = new window.TheriusSDK.TheriusSDK({
                clientToken: tokenRes.clientToken,
                baseUrl: this.config.baseUrl
            });

            // sdk.checkout(options) -> CheckoutWidget, not sdk.create('checkout', options).
            // Field is checkoutConfigId (CheckoutOptions.checkoutConfigId), not configId.
            // amount/currency/country are also embedded in the session JWT and get
            // applied automatically by CheckoutWidget.mount() — passed here too
            // (matches the WooCommerce reference plugin's pattern) as a fallback for
            // the rare case the session row failed to create server-side but the
            // token request otherwise succeeded.
            this.checkout = sdk.checkout({
                checkoutConfigId: this.config.checkoutConfigId,
                amount: tokenRes.amount,
                currency: tokenRes.currency,
                country: tokenRes.country,
                // onNonce's first arg is the raw nonce STRING, not a payload object
                // (same for onApm/onWalletToken's ddcSessionId arg) — see
                // therius-sdk/src/checkout.ts's onNonce/onApm call sites. Sending it
                // straight through as the request body (the previous bug) makes
                // PreOrderController's array_merge($payload, ...) blow up with
                // "Argument #1 must be of type array, string given", which renders
                // as an HTML error page — hence the "Unexpected token '<'" seen
                // client-side. Every branch here must build the actual
                // card/apm/walletData wrapper the purchase API expects (see
                // therius-public-api/handlers_payment.go's purchaseRequest struct).
                onNonce: (nonce, ddcSessionId, vaultConsent) => {
                    const payload = { card: { nonceData: { nonce: nonce, tokenize: vaultConsent } } };
                    if (ddcSessionId) payload.threeDsSetup = { sessionId: ddcSessionId };
                    return this.handlePreOrder(payload);
                },
                onApm: (apm, ddcSessionId) => {
                    const payload = { apm: apm };
                    if (ddcSessionId) payload.threeDsSetup = { sessionId: ddcSessionId };
                    return this.handlePreOrder(payload);
                },
                onWalletToken: (walletData, ddcSessionId) => {
                    const payload = { walletData: walletData };
                    if (ddcSessionId) payload.threeDsSetup = { sessionId: ddcSessionId };
                    return this.handlePreOrder(payload);
                },
                onActionComplete: (actionData) => this.handleActionComplete(actionData)
            });

            this.checkout.mount(this.modalMount);
        } catch (err) {
            this.showLightboxError(err && err.message ? err.message : 'Could not start checkout.');
        }
    }

    buildLightboxDom() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'therius-lightbox-overlay';
        this.overlay.addEventListener('click', (e) => {
            if (e.target === this.overlay) {
                this.closeLightbox();
            }
        });

        const modal = document.createElement('div');
        modal.className = 'therius-lightbox-modal';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'therius-lightbox-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.closeLightbox());

        this.modalMount = document.createElement('div');
        this.modalMount.className = 'therius-lightbox-mount';

        modal.appendChild(closeBtn);
        modal.appendChild(this.modalMount);
        this.overlay.appendChild(modal);
        document.body.appendChild(this.overlay);
    }

    closeLightbox() {
        if (this.checkout && typeof this.checkout.unmount === 'function') {
            this.checkout.unmount();
        }
        if (this.overlay) {
            this.overlay.remove();
        }
        this.overlay = null;
        this.modalMount = null;
        this.checkout = null;
        this.lightboxOpen = false;
        if (this.submitButton) {
            this.submitButton.disabled = false;
        }
    }

    showLightboxError(message) {
        if (!this.modalMount) {
            return;
        }
        const div = document.createElement('div');
        div.className = 'therius-lightbox-error';
        div.textContent = message;
        this.modalMount.appendChild(div);
    }

    /**
     * Shared by onNonce/onApm/onWalletToken. Returning the raw `actionRequired`
     * object here (rather than `true`/`void`) tells the SDK to run its own
     * _handleAction — the same popup/lightbox/DDC-iframe machinery it uses for
     * its own internal calls, already pre-opening any popup synchronously on
     * the shopper's original click inside the widget. Nothing extra to do here
     * for 3DS/APM interaction.
     */
    /**
     * A recoveryAction on the response means PreOrderController's declineResponse()
     * classified this as an actual decline (see its PHP doc comment) — reject with
     * the SDK's DeclineError so CheckoutWidget's built-in smart recovery (remount
     * for a retryable decline, a "try another method" nudge, or a terminal failure
     * state) activates. Anything else (network failure, "Payment already exists",
     * a malformed payload) is a real bug/outage, not a decline — plain Error,
     * unchanged generic handling.
     */
    rejectWithDeclineInfo(reject, res, fallbackMessage) {
        const message = res.error || fallbackMessage;
        if (res.recoveryAction && window.TheriusSDK && window.TheriusSDK.DeclineError) {
            reject(new window.TheriusSDK.DeclineError(message, res.recoveryAction));
        } else {
            reject(new Error(message));
        }
    }

    handlePreOrder(payload) {
        return new Promise((resolve, reject) => {
            this.client.post(this.config.preOrderUrl, JSON.stringify(payload), (response) => {
                try {
                    const res = JSON.parse(response);
                    if (res.actionRequired) {
                        resolve(res.actionRequired);
                    } else if (res.success) {
                        resolve(true);
                        this.finishAndSubmit();
                    } else {
                        this.rejectWithDeclineInfo(reject, res, 'Payment failed');
                    }
                } catch (e) {
                    reject(e);
                }
            });
        });
    }

    handleActionComplete(actionData) {
        return new Promise((resolve, reject) => {
            this.client.post(this.config.preOrderInquiryUrl, JSON.stringify({ paymentCode: actionData.paymentCode }), (response) => {
                try {
                    const res = JSON.parse(response);
                    if (res.success) {
                        resolve(true);
                        this.finishAndSubmit();
                    } else {
                        this.rejectWithDeclineInfo(reject, res, 'Payment failed');
                    }
                } catch (e) {
                    reject(e);
                }
            });
        });
    }

    finishAndSubmit() {
        if (this.checkout && typeof this.checkout.unmount === 'function') {
            this.checkout.unmount();
        }
        if (this.overlay) {
            this.overlay.remove();
        }
        this.overlay = null;
        // Payment already succeeded server-side (TheriusPaymentHandler::pay()
        // picks up the stashed pre-order result); this submit only lets
        // Shopware create the order.
        HTMLFormElement.prototype.submit.call(this.checkoutForm);
    }

    postJson(url, body) {
        return new Promise((resolve) => {
            this.client.post(url, body, (response) => {
                try {
                    resolve(JSON.parse(response));
                } catch (e) {
                    resolve({});
                }
            });
        });
    }

    loadScript(url) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }
}
