<?php

namespace Productbird\Auth;

use WP_Error;
use WP_REST_Request;

/**
 * Handles the OpenID-Connect client flow against the Productbird Better-Auth
 * instance. The flow is as follows:
 *
 * 1. "Connect with Productbird" button sends the browser to the Better-Auth
 *    /authorize endpoint with the usual OIDC parameters.
 * 2. After sign-in / consent the provider redirects the user back to our
 *    redirect URI ( /wp-json/productbird/v1/oidc/callback ).
 * 3. We exchange the code for access & refresh tokens and persist them in a
 *    WordPress option.
 * 4. When required we can call the UserInfo endpoint or refresh the access
 *    token.
 *
 * All data is stored in regular WordPress options so it survives across
 * requests and works for all admins alike.
 */
class OidcClient
{
    /* --------------------------------------------------------------------- */
    /* === Constants ======================================================= */
    /* --------------------------------------------------------------------- */

    /** Option that keeps { access_token, refresh_token, expires_at } */
    private const OPTION_TOKENS   = 'productbird_oidc_tokens';

    /** Option that keeps { client_id, client_secret } */
    private const OPTION_CLIENT   = 'productbird_oidc_client';

    /** Option/Transient prefix for CSRF state storage */
    private const STATE_PREFIX    = 'productbird_oidc_state_';

    /** When we generate a new state we keep it valid for 15 minutes. */
    private const STATE_TTL       = 900; // 15min

    /** Better-Auth production base URL */
    private const PROD_BASE_URL   = 'https://app.productbird.com';

    /** Local development base URL */
    private const LOCAL_BASE_URL  = 'http://localhost:5173';

    /* --------------------------------------------------------------------- */
    /* === Public bootstrap =============================================== */
    /* --------------------------------------------------------------------- */

    public function init(): void
    {
        // Allow the admin to disconnect which simply wipes the stored data.
        add_action('admin_post_productbird_oidc_disconnect', [$this, 'handle_disconnect']);
    }

    /* --------------------------------------------------------------------- */
    /* === Helper getters ================================================== */
    /* --------------------------------------------------------------------- */

    /**
     * Returns the Better-Auth base URL depending on local/remote environment.
     */
    public function get_base_url(): string
    {
        $site_url = home_url();

        if (strpos($site_url, 'localhost') !== false ||
            strpos($site_url, '127.0.0.1')  !== false ||
            strpos($site_url, '.local')     !== false) {
            return self::LOCAL_BASE_URL;
        }

        return self::PROD_BASE_URL;
    }

    /**
     * Fully-qualified redirect URI that Better-Auth will send the user back to.
     */
    public function get_redirect_uri(): string
    {
        return home_url('/wp-json/productbird/v1/oidc/callback');
    }

    /**
     * Retrieve the stored client credentials if we already registered.
     */
    private function get_client_credentials(): ?array
    {
        $client = get_option(self::OPTION_CLIENT, []);
        return isset($client['client_id'], $client['client_secret']) ? $client : null;
    }

    /**
     * Retrieve the stored tokens ( may be expired ).
     */
    private function get_tokens(): ?array
    {
        $tokens = get_option(self::OPTION_TOKENS, []);
        return isset($tokens['access_token'], $tokens['refresh_token'], $tokens['expires_at'])
            ? $tokens
            : null;
    }

    /**
     * Are we currently connected (i.e. we have a non-expired access token)?
     */
    public function is_connected(): bool
    {
        $tokens = $this->get_tokens();
        if (!$tokens) {
            return false;
        }

        // Automatically refresh if expired.
        if (time() >= (int) $tokens['expires_at']) {
            $refresh = $this->refresh_access_token();
            return !is_wp_error($refresh);
        }

        return true;
    }

    /**
     * Generates the "Connect with Productbird" authorization URL.
     * This will dynamically register the WP site as an OIDC client if no
     * credentials exist yet.
     */
    public function build_authorization_url(): ?string
    {
        // Ensure we have a client_id / secret – register otherwise.
        if (!$this->ensure_client_registered()) {
            return null; // Registration failed.
        }

        $client = $this->get_client_credentials();

        if (!$client) {
            return null;
        }

        $state = wp_generate_password(20, false);
        // Store the state server-side so we can validate it later.
        set_transient(self::STATE_PREFIX . $state, 1, self::STATE_TTL);

        $params = [
            'response_type' => 'code',
            'client_id'     => $client['client_id'],
            'redirect_uri'  => $this->get_redirect_uri(),
            'scope'         => 'openid profile email orgs',
            'state'         => $state,
        ];

        return $this->get_base_url() . '/api/auth/oauth2/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /* --------------------------------------------------------------------- */
    /* === Dynamic client registration ==================================== */
    /* --------------------------------------------------------------------- */

    /**
     * Registers the WP site as an OAuth client with Better-Auth.
     * Returns true on success or WP_Error on failure.
     */
    private function register_client()
    {
        $body = [
            'name'          => sprintf('%s (%s)', wp_specialchars_decode(get_bloginfo('name')), home_url()),
            'redirect_uris' => [$this->get_redirect_uri()],
        ];

        $url = $this->get_base_url() . '/api/auth/oauth2/register';

        $response = wp_remote_post(
            $url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            error_log('WP_Error during client registration: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code >= 200 && $code < 300 && isset($data['client_id'], $data['client_secret'])) {
            update_option(self::OPTION_CLIENT, [
                'client_id'     => sanitize_text_field($data['client_id']),
                'client_secret' => sanitize_text_field($data['client_secret']),
            ]);
            return true;
        }

        return new WP_Error('productbird_client_registration_failed', 'Unable to register OAuth client with Productbird.', [
            'status_code' => $code,
            'response'    => $data,
        ]);
    }

    /**
     * Make sure we have a client_id / secret. Attempts to register if missing.
     */
    private function ensure_client_registered(): bool
    {
        if ($this->get_client_credentials()) {
            return true;
        }

        $registered = $this->register_client();

        return !is_wp_error($registered);
    }

    /* --------------------------------------------------------------------- */
    /* === Callback processing ============================================ */
    /* --------------------------------------------------------------------- */

    /**
     * Processes the /oidc/callback REST request. Exchanges the code for
     * tokens, stores them, and finally redirects the user back to the settings
     * page with a status indicator.
     */
    public function process_callback(WP_REST_Request $request)
    {
        $code  = $request->get_param('code');
        $state = $request->get_param('state');

        // Verify state to defend against CSRF.
        if (!$code || !$state || !get_transient(self::STATE_PREFIX . $state)) {
            return new WP_Error('productbird_state_mismatch', 'Invalid state parameter.', ['status' => 400]);
        }
        delete_transient(self::STATE_PREFIX . $state);

        $client = $this->get_client_credentials();
        if (!$client) {
            return new WP_Error('productbird_missing_client', 'Client not registered.', ['status' => 500]);
        }

        // Exchange code → tokens.
        $token_response = wp_remote_post(
            $this->get_base_url() . '/api/auth/oauth2/token',
            [
                'headers' => [
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/x-www-form-urlencoded',
                ],
                'body'    => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->get_redirect_uri(),
                    'client_id'     => $client['client_id'],
                    'client_secret' => $client['client_secret'],
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($token_response)) {
            return $token_response;
        }

        $code_status = wp_remote_retrieve_response_code($token_response);
        $data        = json_decode(wp_remote_retrieve_body($token_response), true);

        if ($code_status < 200 || $code_status >= 300 || !isset($data['access_token'])) {
            return new WP_Error('productbird_token_exchange_failed', 'Failed to exchange code for tokens.', [
                'status'   => $code_status,
                'response' => $data,
            ]);
        }

        // Persist the tokens.
        update_option(self::OPTION_TOKENS, [
            'access_token'  => isset($data['access_token']) ? sanitize_text_field($data['access_token']) : '',
            'refresh_token' => isset($data['refresh_token']) ? sanitize_text_field($data['refresh_token']) : '',
            'expires_at'    => time() + (int) ($data['expires_in'] ?? 3600),
            'id_token'      => isset($data['id_token']) ? sanitize_text_field($data['id_token']) : '',
        ]);

        // Redirect back to the settings screen with a success flag.
        wp_safe_redirect(admin_url('options-general.php?page=productbird&connected=1'));
        exit;
    }

    /* --------------------------------------------------------------------- */
    /* === Token refresh =================================================== */
    /* --------------------------------------------------------------------- */

    /**
     * Refreshes the access token if we have a refresh token available.
     * Returns the updated token array or WP_Error on failure.
     */
    public function refresh_access_token()
    {
        $tokens = $this->get_tokens();
        $client = $this->get_client_credentials();

        if (!$tokens || !$client || empty($tokens['refresh_token'])) {
            return new WP_Error('productbird_no_refresh', 'Unable to refresh access token.');
        }

        $response = wp_remote_post(
            $this->get_base_url() . '/api/auth/oauth2/token',
            [
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $tokens['refresh_token'],
                    'client_id'     => $client['client_id'],
                    'client_secret' => $client['client_secret'],
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code_status = wp_remote_retrieve_response_code($response);
        $data        = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_status < 200 || $code_status >= 300 || !isset($data['access_token'])) {
            return new WP_Error('productbird_refresh_failed', 'Failed to refresh access token.', [
                'status'   => $code_status,
                'response' => $data,
            ]);
        }

        $new_tokens = [
            'access_token'  => isset($data['access_token']) ? sanitize_text_field($data['access_token']) : '',
            'refresh_token' => isset($data['refresh_token']) ? sanitize_text_field($data['refresh_token']) : sanitize_text_field($tokens['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($data['expires_in'] ?? 3600),
            'id_token'      => isset($data['id_token']) ? sanitize_text_field($data['id_token']) : sanitize_text_field($tokens['id_token'] ?? ''),
        ];

        update_option(self::OPTION_TOKENS, $new_tokens);
        return $new_tokens;
    }

    /* --------------------------------------------------------------------- */
    /* === UserInfo ======================================================== */
    /* --------------------------------------------------------------------- */

    /**
     * Calls the /userinfo endpoint with the current access token.
     * Returns an associative array with the claims or WP_Error on failure.
     */
    public function get_userinfo()
    {
        if (!$this->is_connected()) {
            return new WP_Error('productbird_not_connected', 'Productbird not connected.');
        }

        $tokens = $this->get_tokens();

        // Log that we're making the userinfo request and with what token
        error_log('Productbird: Making userinfo request with token: ' . substr($tokens['access_token'], 0, 10) . '...');
        error_log('Productbird: Using base URL: ' . $this->get_base_url());

        // Also log the scopes from the original authentication
        error_log('Productbird: Originally requested scopes: openid profile email orgs');

        // Decode and log ID token if available
        if (!empty($tokens['id_token'])) {
            // ID tokens are JWT format (header.payload.signature)
            $jwt_parts = explode('.', $tokens['id_token']);
            if (count($jwt_parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $jwt_parts[1])), true);
                error_log('Productbird: ID Token payload: ' . wp_json_encode($payload, JSON_PRETTY_PRINT));

                // Check if scopes are in the ID token
                if (isset($payload['scope'])) {
                    error_log('Productbird: Scopes in ID token: ' . $payload['scope']);
                } else {
                    error_log('Productbird: No scopes found in ID token');
                }

                // Check if orgs data is directly in the ID token
                if (isset($payload['orgs'])) {
                    error_log('Productbird: Orgs data found in ID token: ' . wp_json_encode($payload['orgs']));
                } else {
                    error_log('Productbird: No orgs data found in ID token');
                }
            }
        } else {
            error_log('Productbird: No ID token available');
        }

        $response = wp_remote_get(
            $this->get_base_url() . '/api/auth/oauth2/userinfo',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tokens['access_token'],
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            error_log('Productbird: UserInfo request failed with WP_Error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log('Productbird: UserInfo response code: ' . $code);
        error_log('Productbird: UserInfo raw response body: ' . $body);
        error_log('Productbird: UserInfo parsed data: ' . wp_json_encode($data, JSON_PRETTY_PRINT));

        // Specifically check for orgs data
        if (isset($data['orgs'])) {
            error_log('Productbird: Orgs data is present: ' . wp_json_encode($data['orgs']));
        } else {
            error_log('Productbird: ⚠️ Orgs data is missing from the response');
        }

        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }

        return new WP_Error('productbird_userinfo_failed', 'Failed to retrieve userinfo.', [
            'status'   => $code,
            'response' => $data,
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* === Disconnect ====================================================== */
    /* --------------------------------------------------------------------- */

    /**
     * Handles the admin-posted disconnect action – wipes stored credentials
     * and tokens.
     */
    public function handle_disconnect(): void
    {
        check_admin_referer('productbird_disconnect');
        delete_option(self::OPTION_TOKENS);

        // Keep client credentials so future connect is quicker, but allow a
        // hard reset when holding the `reset_client` query parameter.
        if (isset($_GET['reset_client']) && $_GET['reset_client'] === '1') {
            delete_option(self::OPTION_CLIENT);
        }

        wp_safe_redirect(admin_url('options-general.php?page=productbird&disconnected=1'));
        exit;
    }

    public function get_organizations() {
        if ( $this->is_connected() ) {
            $tokens = $this->get_tokens();          // refresh_access_token() is done in is_connected()
            $url    = $this->get_base_url() . '/api/v1/organizations/me';

            $response = wp_remote_get( $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tokens['access_token'],
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            return ( $code >= 200 && $code < 300 ) ? $body : new \WP_Error(
                'productbird_org_failed',
                __( 'Failed to retrieve organization', 'productbird' ),
                [ 'status' => $code, 'response' => $body ]
            );
        }

        return new \WP_Error( 'productbird_not_connected', __( 'Not connected to Productbird', 'productbird' ) );
    }
}