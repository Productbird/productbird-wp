declare global {
	interface Window {
		productbird_tool_magic_descriptions: {
			bulk_action_group_label: string;
			config: import("$lib/utils/types").ToolConfig<"magic-descriptions">;
		} & import("$lib/utils/types").GlobalAdminData;
	}
}

export {};
