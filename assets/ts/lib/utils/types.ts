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

export type ToolConfig<T extends ToolId> = {
	id: T;
	slug: string;
	name: string;
	description: string;
	icon: string;
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
	}[];
};

export type MagicDescriptionsStatusCheckWpJsonResponse = {
	completed_items: {
		id: ProductId;
		name: string;
		html?: string;
	}[];
	remaining_count: number;
};
