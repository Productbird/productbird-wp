interface OidcData {
	is_connected: boolean;
	auth_url?: string;
	disconnect_url?: string;
	name?: string;
}

declare global {
	interface Window {
		productbird_admin: {
			admin_url: string;
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
		};
	}
}

export {};
