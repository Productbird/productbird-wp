<?php
namespace Productbird\Rest;

use Productbird\Auth\OidcClient;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class OrganizationsEndpoint {
    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route(
            'productbird/v1',
            '/organizations',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_org' ],
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
            ]
        );
    }

    public function get_org( WP_REST_Request $request ) {
        $oidc = new OidcClient();
        $orgs  = $oidc->get_organizations();

        if ( $orgs instanceof WP_Error ) {
            return new WP_REST_Response(
                [ 'error' => $orgs->get_error_message(), 'details' => $orgs->get_error_data() ],
                (int) ( $orgs->get_error_data()['status'] ?? 400 )
            );
        }

        return new WP_REST_Response( $orgs );
    }
}