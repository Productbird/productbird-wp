declare global {
	interface Window {
		productbird: import("$lib/utils/types").GlobalAdminData;
		productbird_admin: {
			admin_url: string;
			app_url: string;
			nonce: string;
			settings_page_url: string;
			api_root_url: string;
			current_user: {
				id: number;
				email: string;
				display_name: string;
			};
			oidc: import("$lib/utils/types").GlobalAdminOidcData;
			features: import("$lib/utils/types").GlobalAdminFeatureFlags;
		};
	}
}

export {};
