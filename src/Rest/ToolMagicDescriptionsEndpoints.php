<?php

namespace Productbird\Rest;

use Productbird\Api\Client;
use Productbird\FeatureFlags;
use Productbird\Logger;
use Productbird\Traits\ProductDataHelpers;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Productbird\Traits\ToolsConfig;
use Productbird\Rest\RestUtils;
use function wc_get_product;

/**
 * REST API endpoint for bulk generating product descriptions.
 *
 * This endpoint uses tool-specific meta keys for the Magic Descriptions tool:
 * - generation_status: '_productbird_magic_descriptions_status'
 * - status_id: '_productbird_magic_descriptions_status_id'
 * - error: '_productbird_magic_descriptions_error'
 * - description_draft: '_productbird_magic_descriptions_draft'
 * - delivered: '_productbird_magic_descriptions_delivered'
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class ToolMagicDescriptionsEndpoints {

	use ProductDataHelpers;
	use ToolsConfig;

	private $tool_config;

	/**
	 * Status meta key
	 *
	 * @var string
	 */
	private $meta_status_key;

	/**
	 * Draft meta key
	 *
	 * @var string
	 */
	private $meta_draft_key;

	/**
	 * Delivered meta key
	 *
	 * @var string
	 */
	private $meta_delivered_key;

	/**
	 * Status ID meta key
	 *
	 * @var string
	 */
	private $meta_status_id_key;

	/**
	 * Error meta key
	 *
	 * @var string
	 */
	private $meta_error_key;

	/**
	 * Declined meta key
	 *
	 * @var string
	 */
	private $meta_declined_key;

	/**
	 * Initialize the endpoint.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->meta_status_key    = self::MAGIC_DESCRIPTIONS_META_KEY_STATUS;
		$this->meta_draft_key     = self::MAGIC_DESCRIPTIONS_META_KEY_DRAFT;
		$this->meta_delivered_key = self::MAGIC_DESCRIPTIONS_META_KEY_DELIVERED;
		$this->meta_status_id_key = self::MAGIC_DESCRIPTIONS_META_KEY_STATUS_ID;
		$this->meta_error_key     = self::MAGIC_DESCRIPTIONS_META_KEY_ERROR;
		$this->meta_declined_key  = self::MAGIC_DESCRIPTIONS_META_KEY_DECLINED;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the endpoint routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk_generation' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'productIds' => array(
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => function ( $param ) {
							return array_map( 'absint', $param );
						},
					),
					'mode'       => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'auto-apply', 'review' ),
						'default'  => 'review',
					),
				),
			)
		);

		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/callback',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => array( RestUtils::class, 'verify_webhook_signature' ),
			)
		);

		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status_check' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'product_ids' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return ! empty( $param );
						},
						'sanitize_callback' => function ( $param ) {
							$ids = explode( ',', $param );
							return array_map( 'absint', $ids );
						},
					),
				),
			)
		);

		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_apply_description' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'productId'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'description' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);

		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/decline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_decline_description' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'productId' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/undo-decline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_undo_decline_description' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'productId' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// NEW: Pre-flight read-only check used by the UI to warn merchants that some products have been processed
		register_rest_route(
			'productbird/v1',
			'/magic-descriptions/preflight',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_preflight_check' ),
				'permission_callback' => array( RestUtils::class, 'can_manage_woocommerce_permission' ),
				'args'                => array(
					'product_ids' => array(
						'required'          => true,
						'type'              => 'string', // comma-separated IDs – we sanitise to array below
						'validate_callback' => function ( $param ) {
							return ! empty( $param );
						},
						'sanitize_callback' => function ( $param ) {
							$ids = explode( ',', $param );
							return array_map( 'absint', $ids );
						},
					),
				),
			)
		);
	}

	/**
	 * Check if a product has a pending description that needs review.
	 *
	 * @param int $product_id The product ID
	 * @return array|false Returns product data if it needs review, false otherwise
	 */
	private function get_product_needing_review( int $product_id ) {
		$draft     = get_post_meta( $product_id, $this->meta_draft_key, true );
		$delivered = get_post_meta( $product_id, $this->meta_delivered_key, true );
		$status    = get_post_meta( $product_id, $this->meta_status_key, true );

		if ( ! empty( $draft ) && ! $delivered && $status === 'completed' ) {
			$product = \wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			return array(
				'id'           => $product_id,
				'name'         => $product->get_name(),
				'html'         => $draft,
				'current_html' => wpautop( $product->get_description() ),
				'status'       => $this->get_product_status( $product_id ),
			);
		}

		return false;
	}

	/**
	 * Handle the bulk generation request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response object.
	 */
	public function handle_bulk_generation( WP_REST_Request $request ) {
		$product_ids = $request->get_param( 'productIds' );
		$mode        = $request->get_param( 'mode' );

		// Ensure the list contains unique product IDs to avoid redundant processing and duplicate
		// entries in both `scheduled_items` and `pending_items` responses.
		if ( is_array( $product_ids ) ) {
			$product_ids = array_values( array_unique( array_map( 'absint', $product_ids ) ) );
		}

		Logger::info(
			'Bulk generation request started',
			array(
				'product_count' => count( $product_ids ),
				'mode'          => $mode,
				'product_ids'   => $product_ids,
			)
		);

		$is_valid_batch_size = RestUtils::is_valid_batch_size( count( $product_ids ), 'magic-descriptions' );

		if ( $is_valid_batch_size instanceof WP_REST_Response ) {
			Logger::warning(
				'Bulk generation failed: invalid batch size',
				array(
					'product_count' => count( $product_ids ),
					'product_ids'   => $product_ids,
				)
			);
			return $is_valid_batch_size;
		}

		// Get the API key from settings
		$options = get_option( 'productbird_settings', array() );
		$api_key = $options['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			Logger::error( 'Bulk generation failed: API key not configured' );
			return new \WP_REST_Response(
				array(
					'error' => __( 'API key not configured', 'productbird' ),
					'code'  => 'api_key_missing',
					'data'  => array( 'status' => 400 ),
				),
				400
			);
		}

		$client                  = new Client( $api_key );
		$status_ids              = array();
		$payloads                = array();
		$products_needing_review = array();

		// Build payloads for all products first
		foreach ( $product_ids as $product_id ) {
			$product = \wc_get_product( $product_id );
			if ( ! $product ) {
				Logger::warning(
					'Product not found during bulk generation',
					array(
						'product_id' => $product_id,
					)
				);
				continue;
			}

			// Skip products that have already had their description delivered or explicitly declined
			$delivered_flag = get_post_meta( $product_id, $this->meta_delivered_key, true );
			$declined_flag  = get_post_meta( $product_id, $this->meta_declined_key, true );

			/*
			 * Starting from v0.2.0 we allow merchants to regenerate product descriptions at any time, even if a
			 * description was previously delivered (accepted) or explicitly declined.  When this happens we clear all
			 * Productbird-related meta data so the product can be processed as if it was never generated before.  We
			 * still preserve the existing review workflow: if a product currently has a draft that hasn't been
			 * accepted/declined yet we continue to offer it for review and skip scheduling a new generation run.
			 */

			if ( $delivered_flag === 'yes' || $declined_flag === 'yes' ) {
				Logger::info(
					'Product marked as delivered/declined – clearing metadata to allow regeneration',
					array(
						'product_id'    => $product_id,
						'product_name'  => $product->get_name(),
						'was_delivered' => $delivered_flag === 'yes',
						'was_declined'  => $declined_flag === 'yes',
					)
				);

				// Reset all tool-specific meta so the product can be processed again.
				$this->clear_metadata( $product_id );
			}

			// Check if product has a pending draft that needs review
			$product_review_data = $this->get_product_needing_review( $product_id );
			if ( $product_review_data !== false ) {
				$products_needing_review[] = $product_review_data;

				Logger::info(
					'Product skipped - needs review',
					array(
						'product_id'   => $product_id,
						'product_name' => $product->get_name(),
					)
				);
				continue;
			}

			// Clear delivered flag from any previous generation run so the product can be processed again
			delete_post_meta( $product_id, $this->meta_delivered_key );

			$this->set_generation_status( $product_id, 'queued' );

			Logger::log_product_processing( $product_id, 'bulk_generation', 'queued' );

			$payload = $this->build_product_payload(
				$product,
				array(
					'tone'         => $options['tone'] ?? null,
					'formality'    => $options['formality'] ?? null,
					'callback_url' => rest_url( "productbird/v1/magic-descriptions/callback?mode={$mode}" ),
				)
			);

			$payloads[] = $payload;
		}

		if ( empty( $payloads ) && empty( $products_needing_review ) ) {
			Logger::error(
				'Bulk generation failed: no valid products found',
				array(
					'original_product_ids' => $product_ids,
					'valid_products_count' => 0,
				)
			);
			return new \WP_REST_Response(
				array(
					'error' => __( 'No valid products found to process.', 'productbird' ),
					'code'  => 'no_valid_products',
					'data'  => array( 'status' => 400 ),
				),
				400
			);
		}

		// Only make API call if we have products to schedule
		if ( ! empty( $payloads ) ) {
			Logger::log_api_request( 'generate_product_description_bulk', $payloads );

			$response = $client->generate_product_description_bulk( $payloads );

			if ( is_wp_error( $response ) ) {
				Logger::log_api_response( 'generate_product_description_bulk', $response, true );
				return new \WP_REST_Response(
					array(
						'error' => $response->get_error_message(),
						'code'  => $response->get_error_code(),
						'data'  => array( 'status' => 400 ),
					),
					400
				);
			}

			Logger::log_api_response( 'generate_product_description_bulk', $response );

			$status_ids = $response['results'] ?? array();
		}

		// Transform status_ids to use snake_case keys for scheduled_items
		$scheduled_items = array();
		foreach ( $status_ids as $item ) {
			$scheduled_items[] = array(
				'product_id' => $item['productId'] ?? null,
				'status_id'  => $item['statusId'] ?? null,
			);
		}

		Logger::info(
			'Bulk generation request completed successfully',
			array(
				'mode'                 => $mode,
				'scheduled_count'      => count( $status_ids ),
				'needing_review_count' => count( $products_needing_review ),
				'total_products'       => count( $status_ids ) + count( $products_needing_review ),
			)
		);

		return rest_ensure_response(
			array(
				'mode'                => $mode,
				'status_ids'          => $status_ids,
				'has_scheduled_items' => ! empty( $status_ids ),
				'scheduled_items'     => $scheduled_items,
				'pending_items'       => $products_needing_review,
				'has_pending_items'   => ! empty( $products_needing_review ),
				'status'              => 'queued',
			)
		);
	}

	/**
	 * Handles the incoming callback and updates the product.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$mode = $request->get_param( 'mode' );

		$product_id  = isset( $data['productId'] ) ? (int) $data['productId'] : 0;
		$description = $data['description'] ?? null;

		Logger::info(
			'Callback received',
			array(
				'product_id'         => $product_id,
				'mode'               => $mode,
				'has_description'    => ! empty( $description ),
				'description_blocks' => is_array( $description ) ? count( $description ) : 0,
			)
		);

		if ( $product_id <= 0 || ! is_array( $description ) ) {
			Logger::error(
				'Callback failed: missing productId or description',
				array(
					'product_id'       => $product_id,
					'description_type' => gettype( $description ),
				)
			);
			return new WP_Error( 'productbird_missing_fields', __( 'Missing productId or description.', 'productbird' ), array( 'status' => 400 ) );
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			Logger::error(
				'Callback failed: product not found',
				array(
					'product_id' => $product_id,
				)
			);
			return new WP_Error( 'productbird_product_not_found', __( 'Product not found.', 'productbird' ), array( 'status' => 404 ) );
		}

		$html_parts = array();

		foreach ( $description as $block ) {
			if ( ! is_array( $block ) || empty( $block['text'] ) ) {
				Logger::warning(
					'Skipping invalid description block',
					array(
						'product_id' => $product_id,
						'block_type' => gettype( $block ),
						'has_text'   => isset( $block['text'] ),
					)
				);
				continue;
			}

			// Define allowed tags
			$allowed_tags = array( 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'span' );

			// Check if tag is allowed, default to 'p' if not
			$tag = isset( $block['tag'] ) && in_array( $block['tag'], $allowed_tags ) ? $block['tag'] : 'p';

			// Sanitize text
			$text = wp_kses_post( $block['text'] );

			// Handle attributes if provided
			$attributes = '';
			if ( isset( $block['attributes'] ) && is_array( $block['attributes'] ) ) {
				foreach ( $block['attributes'] as $attr_name => $attr_value ) {
					// Sanitize attribute name and value
					$attr_name   = sanitize_key( $attr_name );
					$attr_value  = esc_attr( $attr_value );
					$attributes .= sprintf( ' %s="%s"', $attr_name, $attr_value );
				}
			}

			$html_parts[] = sprintf( '<%1$s%2$s>%3$s</%1$s>', $tag, $attributes, $text );
		}

		if ( empty( $html_parts ) ) {
			Logger::error(
				'Callback failed: empty description content',
				array(
					'product_id'            => $product_id,
					'original_blocks_count' => count( $description ),
				)
			);
			return new WP_Error( 'productbird_empty_description', __( 'Empty description content.', 'productbird' ), array( 'status' => 400 ) );
		}

		Logger::log_product_processing(
			$product_id,
			'description_generated',
			'processing',
			array(
				'html_blocks_count' => count( $html_parts ),
				'mode'              => $mode,
			)
		);

		update_post_meta( $product_id, $this->meta_status_key, 'completed' );
		delete_post_meta( $product_id, $this->meta_status_id_key );

			// Store the generated description as a draft first
			$description_html = implode( "\n", $html_parts );
			update_post_meta( $product_id, $this->meta_draft_key, $description_html );

			Logger::debug(
				'Description stored as draft',
				array(
					'product_id'         => $product_id,
					'description_length' => strlen( $description_html ),
				)
			);

		if ( $mode === 'auto-apply' ) {
			// Auto-apply the description
			$product->set_description( $description_html );
			$product->save();

			Logger::log_product_processing( $product_id, 'description_auto_applied', 'completed' );

			$this->set_generation_status( $product_id, 'completed' );

			// Mark as delivered when descriptions are auto-applied so they are not offered for review later
			$this->set_delivered( $product_id, true );

			delete_post_meta( $product_id, $this->meta_status_id_key );
		} else {
			Logger::log_product_processing( $product_id, 'description_ready_for_review', 'completed' );
		}

		Logger::info(
			'Callback processed successfully',
			array(
				'product_id' => $product_id,
				'mode'       => $mode,
			)
		);

		// Return a 200 response
		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * Get the status of completed descriptions.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_status_check( WP_REST_Request $request ) {
		$product_ids = $request->get_param( 'product_ids' );

		Logger::debug(
			'Getting completed descriptions',
			array(
				'product_ids'   => $product_ids,
				'product_count' => is_array( $product_ids ) ? count( $product_ids ) : 0,
			)
		);

		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			Logger::warning( 'No product IDs provided for completed descriptions check' );
			return new WP_Error(
				'missing_product_ids',
				__( 'No product IDs provided.', 'productbird' ),
				array( 'status' => 400 )
			);
		}

		$completed_items = array();
		$remaining_count = 0;

		foreach ( $product_ids as $product_id ) {
			$status = get_post_meta( $product_id, $this->meta_status_key, true );

			// Add more detailed logging
			Logger::debug(
				'Checking product status',
				array(
					'product_id'   => $product_id,
					'status'       => $status,
					'has_draft'    => ! empty( get_post_meta( $product_id, $this->meta_draft_key, true ) ),
					'is_delivered' => ! empty( get_post_meta( $product_id, $this->meta_delivered_key, true ) ),
				)
			);

			if ( $status === 'completed' ) {
				// Skip if we've already delivered this description
				$already_delivered = (bool) get_post_meta( $product_id, $this->meta_delivered_key, true );

				if ( $already_delivered ) {
					Logger::debug(
						'Skipping already delivered description',
						array(
							'product_id' => $product_id,
						)
					);
					continue;
				}

				// Only include products that have been marked as completed
				$description_draft = get_post_meta( $product_id, $this->meta_draft_key, true );

				if ( ! empty( $description_draft ) ) {
					$product = \wc_get_product( $product_id );

					$completed_items[] = array(
						'id'           => $product_id,
						'name'         => $product ? $product->get_name() : '',
						'html'         => $description_draft,
						'current_html' => $product ? wpautop( $product->get_description() ) : '',
						'status'       => $this->get_product_status( $product_id ),
					);

					Logger::debug(
						'Description added to completed items',
						array(
							'product_id'   => $product_id,
							'product_name' => $product ? $product->get_name() : 'Unknown',
						)
					);
				}
			} elseif ( $status === 'queued' || $status === 'running' ) {
				// Count how many are still in progress
				++$remaining_count;
			}
		}

		// NOTE: We no longer mark descriptions as delivered here. In review mode the merchant still needs to
		// accept or decline the draft, so doing so would hide the description from subsequent review sessions.
		// Drafts are now marked as delivered either when they are auto-applied (see above) or when the merchant
		// explicitly accepts them via the dedicated "apply" endpoint.

		Logger::info(
			'Completed descriptions retrieved',
			array(
				'completed_count' => count( $completed_items ),
				'remaining_count' => $remaining_count,
				'total_checked'   => count( $product_ids ),
			)
		);

		return new WP_REST_Response(
			array(
				'completed_items' => $completed_items,
				'remaining_count' => $remaining_count,
			)
		);
	}

	/**
	 * Set the generation status for a product.
	 *
	 * @param int    $product_id The product ID
	 * @param string $status The status ('queued', 'processing', 'completed', 'error')
	 * @return bool True on success, false on failure
	 */
	private function set_generation_status( int $product_id, string $status ): bool {
		$result = update_post_meta( $product_id, $this->meta_status_key, $status );

		return $result;
	}

	/**
	 * Set the status ID for a product.
	 *
	 * @param int    $product_id The product ID
	 * @param string $status_id The status ID from the API
	 * @return bool True on success, false on failure
	 */
	private function set_status_id( int $product_id, string $status_id ): bool {
		if ( empty( $status_id ) ) {
			Logger::error(
				'Empty status ID provided',
				array(
					'product_id' => $product_id,
				)
			);
			error_log( "Empty status ID provided for product {$product_id}" );
			return false;
		}

		$result = update_post_meta( $product_id, $this->meta_status_id_key, $status_id );

		return $result;
	}

	/**
	 * Set an error message for a product.
	 *
	 * @param int    $product_id The product ID
	 * @param string $error_message The error message
	 * @return bool True on success, false on failure
	 */
	private function set_error( int $product_id, string $error_message ): bool {
		if ( empty( $error_message ) ) {
			Logger::error(
				'Empty error message provided',
				array(
					'product_id' => $product_id,
				)
			);
			error_log( "Empty error message provided for product {$product_id}" );
			return false;
		}

		$result = update_post_meta( $product_id, $this->meta_error_key, $error_message );

		return $result;
	}

	/**
	 * Set the description draft for a product.
	 *
	 * @param int    $product_id The product ID
	 * @param string $draft The draft description
	 * @return bool True on success, false on failure
	 */
	private function set_description_draft( int $product_id, string $draft ): bool {
		if ( empty( $draft ) ) {
			error_log( "Empty draft provided for product {$product_id}" );
			return false;
		}

		$result = update_post_meta( $product_id, $this->meta_draft_key, $draft );

		return $result;
	}

	/**
	 * Mark a product as delivered.
	 *
	 * @param int  $product_id The product ID
	 * @param bool $delivered Whether the product description was delivered
	 * @return bool True on success, false on failure
	 */
	private function set_delivered( int $product_id, bool $delivered = true ): bool {
		return update_post_meta( $product_id, $this->meta_delivered_key, $delivered ? 'yes' : 'no' ) !== false;
	}

	/**
	 * Mark a product description as declined.
	 *
	 * @param int  $product_id The product ID
	 * @param bool $declined Whether the product description was declined
	 * @return bool True on success, false on failure
	 */
	private function set_declined( int $product_id, bool $declined = true ): bool {
		return update_post_meta( $product_id, $this->meta_declined_key, $declined ? 'yes' : 'no' ) !== false;
	}

	/**
	 * Get the current status of a product description.
	 *
	 * @param int $product_id The product ID
	 * @return string The status: 'accepted', 'declined', or 'pending'
	 */
	private function get_product_status( int $product_id ): string {
		$delivered = get_post_meta( $product_id, $this->meta_delivered_key, true );
		$declined  = get_post_meta( $product_id, $this->meta_declined_key, true );

		if ( $delivered === 'yes' ) {
			return 'accepted';
		}

		if ( $declined === 'yes' ) {
			return 'declined';
		}

		return 'pending';
	}

	/**
	 * Clear all Magic Descriptions metadata for a product.
	 *
	 * @param int $product_id The product ID
	 * @return bool True if all metadata was cleared successfully
	 */
	private function clear_metadata( int $product_id ): bool {
		Logger::debug(
			'Clearing metadata for product',
			array(
				'product_id' => $product_id,
			)
		);

		$meta_keys = array(
			$this->meta_status_key,
			$this->meta_status_id_key,
			$this->meta_error_key,
			$this->meta_draft_key,
			$this->meta_delivered_key,
			$this->meta_declined_key,
		);

		$success      = true;
		$cleared_keys = array();
		$failed_keys  = array();

		foreach ( $meta_keys as $meta_key ) {
			if ( delete_post_meta( $product_id, $meta_key ) ) {
				$cleared_keys[] = $meta_key;
			} else {
				$failed_keys[] = $meta_key;
				$success       = false;
			}
		}

		if ( $success ) {
			Logger::info(
				'All metadata cleared successfully',
				array(
					'product_id'   => $product_id,
					'cleared_keys' => $cleared_keys,
				)
			);
		} else {
			Logger::error(
				'Failed to clear some metadata',
				array(
					'product_id'   => $product_id,
					'cleared_keys' => $cleared_keys,
					'failed_keys'  => $failed_keys,
				)
			);
		}

		return $success;
	}

	/**
	 * Handle applying a description to a product.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_apply_description( WP_REST_Request $request ) {
		$product_id  = $request->get_param( 'productId' );
		$description = $request->get_param( 'description' );

		Logger::info(
			'Applying description to product',
			array(
				'product_id'      => $product_id,
				'has_description' => ! empty( $description ),
			)
		);

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			Logger::error(
				'Product not found when applying description',
				array(
					'product_id' => $product_id,
				)
			);
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'productbird' ),
				array( 'status' => 404 )
			);
		}

		// Use provided description or get from draft
		if ( empty( $description ) ) {
			$description = get_post_meta( $product_id, $this->meta_draft_key, true );
		}

		if ( empty( $description ) ) {
			Logger::error(
				'No description available to apply',
				array(
					'product_id' => $product_id,
				)
			);
			return new WP_Error(
				'no_description',
				__( 'No description available to apply.', 'productbird' ),
				array( 'status' => 400 )
			);
		}

		// Apply the description
		$product->set_description( $description );
		$product->save();

		// Mark as delivered and clear declined status
		$this->set_delivered( $product_id, true );
		delete_post_meta( $product_id, $this->meta_declined_key );

		Logger::log_product_processing( $product_id, 'description_applied_manually', 'completed' );

		Logger::info(
			'Description applied successfully',
			array(
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
			)
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Description applied successfully.', 'productbird' ),
			)
		);
	}

	/**
	 * Handle declining a description for a product.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_decline_description( WP_REST_Request $request ) {
		$product_id = $request->get_param( 'productId' );

		Logger::info(
			'Declining description for product',
			array(
				'product_id' => $product_id,
			)
		);

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			Logger::error(
				'Product not found when declining description',
				array(
					'product_id' => $product_id,
				)
			);
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'productbird' ),
				array( 'status' => 404 )
			);
		}

		// Mark as declined
		$this->set_declined( $product_id, true );

		Logger::log_product_processing( $product_id, 'description_declined', 'completed' );

		Logger::info(
			'Description declined successfully',
			array(
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
			)
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Description declined.', 'productbird' ),
			)
		);
	}

	/**
	 * Handle undoing a declined description for a product.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_undo_decline_description( WP_REST_Request $request ) {
		$product_id = $request->get_param( 'productId' );

		Logger::info(
			'Undoing declined description for product',
			array(
				'product_id' => $product_id,
			)
		);

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			Logger::error(
				'Product not found when undoing declined description',
				array(
					'product_id' => $product_id,
				)
			);
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'productbird' ),
				array( 'status' => 404 )
			);
		}

		// Mark as not declined
		$this->set_declined( $product_id, false );

		Logger::log_product_processing( $product_id, 'description_declined_undone', 'completed' );

		Logger::info(
			'Description declined undone successfully',
			array(
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
			)
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Description declined undone.', 'productbird' ),
			)
		);
	}

	/**
	 * Pre-flight endpoint – returns current processing status for the supplied products but performs **no** mutations.
	 *
	 * This allows the UI to warn the merchant when some selected products already have an accepted or declined
	 * description that will be overridden.
	 *
	 * Response shape:
	 * [
	 *   {
	 *     id: 123,
	 *     name: "My product",
	 *     status: "accepted"|"declined"|"pending"|"never_generated"
	 *   },
	 *   ...
	 * ]
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_preflight_check( WP_REST_Request $request ) {
		$product_ids = $request->get_param( 'product_ids' );

		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return new WP_Error( 'missing_product_ids', __( 'No product IDs provided.', 'productbird' ), array( 'status' => 400 ) );
		}

		$results = array();

		foreach ( $product_ids as $product_id ) {
			$product = \wc_get_product( $product_id );
			if ( ! $product ) {
				continue; // silently skip invalid ids – the UI already filters by selected posts.
			}

			// Determine current status.
			$delivered = get_post_meta( $product_id, $this->meta_delivered_key, true );
			$declined  = get_post_meta( $product_id, $this->meta_declined_key, true );
			$draft     = get_post_meta( $product_id, $this->meta_draft_key, true );

			if ( 'yes' === $delivered ) {
				$status = 'accepted';
			} elseif ( 'yes' === $declined ) {
				$status = 'declined';
			} elseif ( ! empty( $draft ) ) {
				$status = 'pending';
			} else {
				$status = 'never_generated';
			}

			$results[] = array(
				'id'     => $product_id,
				'name'   => $product->get_name(),
				'status' => $status,
			);
		}

		return rest_ensure_response( array( 'items' => $results ) );
	}
}
