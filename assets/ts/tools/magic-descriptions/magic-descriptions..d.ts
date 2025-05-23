declare global {
	interface Window {
		productbird_tool_product_description: {
			max_batch: number;
			bulk_action_group_label: string;
			config: import("$lib/utils/types").ToolConfig<"magic-descriptions">;
		};
	}
}

export {};
