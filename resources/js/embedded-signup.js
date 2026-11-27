/**
 * WhatsApp Embedded Signup (v4) — "Connect with Meta".
 * 1. Load the Facebook JS SDK and FB.init with the Tech Provider app.
 * 2. FB.login with the Embedded Signup configuration → returns a 5-minute code.
 * 3. A window "message" event (origin *.facebook.com, type WA_EMBEDDED_SIGNUP)
 *    carries waba_id / phone_number_id / business_id.
 * 4. Both are posted to the server, which exchanges the code and finishes onboarding.
 */
export default function embeddedSignup(config) {
    return {
        appId: config.appId,
        configId: config.configId,
        version: config.version,
        url: config.url,
        pin: config.pin,
        name: '',
        sdkReady: false,
        busy: false,
        step: 0, // 0 idle · 1 Meta popup open · 2 finishing on server · 3 done
        error: null,
        code: null,
        session: null,
        submitted: false,

        init() {
            window.addEventListener('message', (event) => this.onMessage(event));

            if (window.FB) {
                this.initSdk();
                return;
            }

            window.fbAsyncInit = () => this.initSdk();

            if (!document.getElementById('facebook-jssdk')) {
                const s = document.createElement('script');
                s.id = 'facebook-jssdk';
                s.async = true;
                s.defer = true;
                s.crossOrigin = 'anonymous';
                s.src = 'https://connect.facebook.net/en_US/sdk.js';
                s.onerror = () => (this.error = 'Could not load the Facebook SDK (blocked by an ad-blocker or network).');
                document.body.appendChild(s);
            }
        },

        initSdk() {
            window.FB.init({ appId: this.appId, autoLogAppEvents: true, xfbml: true, version: this.version });
            this.sdkReady = true;
        },

        onMessage(event) {
            if (typeof event.origin !== 'string' || !event.origin.endsWith('facebook.com')) return;

            let data;
            try {
                data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            } catch (e) {
                return;
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;

            if (data.event === 'CANCEL') {
                this.busy = false;
                this.step = 0;
                this.error = 'Signup was cancelled' + (data.data?.current_step ? ` at step ${data.data.current_step}` : '') + '.';
                return;
            }

            if (data.event === 'ERROR') {
                this.busy = false;
                this.step = 0;
                this.error = data.data?.error_message || 'Meta reported an error during signup.';
                return;
            }

            if (String(data.event || '').startsWith('FINISH')) {
                this.session = { event: data.event, ...(data.data || {}) };
                this.maybeComplete();
            }
        },

        launch() {
            if (!this.sdkReady || this.busy) return;
            if (!/^\d{6}$/.test(this.pin)) {
                this.error = 'The two-step verification PIN must be exactly 6 digits.';
                return;
            }

            this.error = null;
            this.code = null;
            this.session = null;
            this.submitted = false;
            this.busy = true;
            this.step = 1;

            // Must run directly inside the click handler (popup blockers).
            window.FB.login(
                (response) => {
                    if (response && response.authResponse && response.authResponse.code) {
                        this.code = response.authResponse.code;
                        this.maybeComplete();
                    } else {
                        this.busy = false;
                        this.step = 0;
                        if (!this.error) this.error = 'The Meta window was closed before finishing all steps.';
                    }
                },
                {
                    config_id: this.configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: { setup: {} },
                },
            );
        },

        maybeComplete() {
            if (!this.code || this.submitted) return;

            // The asset ids normally arrive just before / after the code — give them a moment.
            if (!this.session && !this._waiting) {
                this._waiting = true;
                setTimeout(() => {
                    this._waiting = false;
                    this.complete();
                }, 6000);
                return;
            }

            if (this.session) this.complete();
        },

        async complete() {
            if (this.submitted || !this.code) return;
            this.submitted = true;
            this.step = 2;

            try {
                const res = await window.http(this.url, {
                    method: 'POST',
                    body: {
                        code: this.code,
                        event: this.session?.event ?? null,
                        waba_id: this.session?.waba_id ?? null,
                        phone_number_id: this.session?.phone_number_id ?? null,
                        business_id: this.session?.business_id ?? null,
                        name: this.name || null,
                        pin: this.pin,
                    },
                });
                this.step = 3;
                window.location.href = res.redirect;
            } catch (e) {
                this.error = e.message;
                this.busy = false;
                this.step = 0;
            }
        },
    };
}
