export type ProductId = number & { readonly _brand: "productId" };

export type OrganizationMe = {
	slug: string;
	name: string;
	id: number;
	role: string;
	balance: number;
};

export type ToolConfigEndpoint = {
	method: string;
	endpoint: string;
	callback_url?: string;
	max_batch?: number;
};

export type ToolId = "magic-descriptions";

export type MagicDescriptionsMetaKeys = {
	generation_status: string;
	status_id: string;
	error: string;
	description_draft: string;
	delivered: string;
	declined: string;
};

export type ToolMetaKeys = {
	"magic-descriptions": MagicDescriptionsMetaKeys;
};

export type ToolConfig<T extends ToolId> = {
	id: T;
	slug: string;
	name: string;
	description: string;
	icon: string;
	max_batch_size: number;
	bulk_action_group_label: string;
	shared_meta_keys: {
		global_status: string;
		last_updated: string;
	};
	meta_keys: ToolMetaKeys[T];
	endpoints: Record<string, ToolConfigEndpoint>;
};

// Type helper to get the config for a specific tool
export type GetToolConfig<T extends ToolId> = ToolConfig<T>;

// Type helper to get all available tool configs
export type AllToolConfigs = {
	[K in ToolId]: ToolConfig<K>;
};

export type MagicDescriptionsBulkWpJsonResponse = {
	mode: "auto-apply" | "review";
	scheduled: number;
	status: "queued";
	status_ids: Array<{
		index: number;
		status: string;
		statusId: string;
		productId: string;
	}>;
	/**
	 * For items taht are scheduled
	 */
	has_scheduled_items: boolean;
	scheduled_items: {
		product_id: string;
		status_id: string;
	}[];
	/**
	 * For items that are still in pending state.
	 */
	has_pending_items: boolean;
	pending_items: {
		id: ProductId;
		name: string;
		html?: string;
		/**
		 * The product's current saved description (HTML) returned from the API so the UI can show a side-by-side comparison.
		 */
		current_html?: string;
	}[];
};

export type MagicDescriptionsStatusCheckWpJsonResponse = {
	completed_items: {
		id: ProductId;
		name: string;
		html?: string;
		/**
		 * The product's current saved description (HTML) returned from the API so the UI can show a side-by-side comparison.
		 */
		current_html?: string;
		/**
		 * The current status of the description: 'pending', 'accepted', or 'declined'
		 */
		status?: "pending" | "accepted" | "declined";
	}[];
	remaining_count: number;
};

// Response from /magic-descriptions/preflight endpoint
export type MagicDescriptionsPreflightWpJsonResponse = {
	items: {
		id: ProductId;
		name: string;
		status: "accepted" | "declined" | "pending" | "never_generated";
	}[];
};

/**
 * Global window obj.
 */

export interface GlobalAdminOidcData {
	is_connected: boolean;
	auth_url?: string;
	disconnect_url?: string;
	name?: string;
}

export interface GlobalAdminFeatureFlags {
	oidc: boolean;
}

export interface GlobalAdminData {
	admin_url: string;
	app_url: string;
	nonce: string;
	settings_page_url: string;
	api_root_url: string;
}
