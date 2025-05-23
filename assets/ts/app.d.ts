interface OidcData {
	is_connected: boolean;
	auth_url?: string;
	disconnect_url?: string;
	name?: string;
}

interface FeatureFlags {
	oidc: boolean;
}

interface GlobalAdminData {
	admin_url: string;
	app_url: string;
	nonce: string;
	settings_page_url: string;
	api_root_url: string;
}

declare global {
	interface Window {
		productbird: GlobalAdminData;
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
			oidc: {
				is_connected: boolean;
				auth_url: string;
				disconnect_url: string;
				name: string;
			};
			features: FeatureFlags;
		};
	}
}

export {};
