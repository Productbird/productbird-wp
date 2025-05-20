<?php

namespace Productbird\Rest;

use Productbird\Auth\OidcClient;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Public endpoint that is used as the redirect_uri for the OIDC
 * Authorization-Code flow. The provider will call it with
 *   ?code=...&state=...
 * and we delegate the heavy lifting to the OidcClient helper.
 */
class OidcCallbackEndpoint
{
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(
            'productbird/v1',
            '/oidc/callback',
            [
                'methods'             => [\WP_REST_Server::READABLE], // GET
                'callback'            => [$this, 'handle_callback'],
                'permission_callback' => '__return_true', // public endpoint
                'args'                => [
                    'code'  => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'state' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );
    }

    /**
     * Delegates to OidcClient for processing.
     *
     * @param WP_REST_Request $request
     */
    public function handle_callback(WP_REST_Request $request)
    {
        $client  = new OidcClient();
        $result = $client->process_callback($request);

        if ($result instanceof WP_Error) {
            return new WP_REST_Response([
                'error'   => $result->get_error_message(),
                'details' => $result->get_error_data(),
            ], (int) ($result->get_error_data()['status'] ?? 400));
        }

        // process_callback will normally redirect + exit;
        return new WP_REST_Response(['success' => true]);
    }
}