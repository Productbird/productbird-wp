// WordPress REST API helper for Productbird plugin.
//
// This utility wraps `fetch` so that calls automatically:
//  • Prepend the WP REST root (`wpApiSettings.root` or `/wp-json/`).
//  • Add the `X-WP-Nonce` header when available for authenticated requests.
//  • JSON-encode / decode bodies.
//
// It then exposes convenience helpers for the routes used by the Productbird
// plugin (settings + generation-status).

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

export interface ProductbirdSettings {
	api_key: string;
	tone: string;
	formality: string;
}

export type GenerationStatus = "none" | "running" | "completed" | "error";

// ─────────────────────────────────────────────────────────────────────────────
// Global type augmentation (avoid `any`)
// ─────────────────────────────────────────────────────────────────────────────

declare global {
	interface Window {
		wpApiSettings?: {
			root: string;
			nonce: string;
		};
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the REST API root URL (always ends with a trailing slash).
 */
function getApiRoot(): string {
	// The global emitted by WordPress when using wp_add_inline_script + apiFetch.
	// See https://developer.wordpress.org/rest-api/using-the-rest-api/#global-javascript-variables
	const root = window.wpApiSettings?.root ?? "/wp-json/";
	return root.endsWith("/") ? root : `${root}/`;
}

/**
 * Try to obtain the WP nonce that authorises authenticated requests.
 */
function getNonce(): string | undefined {
	return window.wpApiSettings?.nonce;
}

interface RequestOptions extends Omit<RequestInit, "body"> {
	/** JSON body that will be stringified automatically. */
	body?: unknown;
	/** Whether to automatically include the X-WP-Nonce header (default: true). */
	auth?: boolean;
}

async function request<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
	const { body, auth = true, headers, ...rest } = options;

	// Build full URL.
	const url = new URL(path.replace(/^\//, ""), getApiRoot()).toString();

	// Default headers.
	const defaultHeaders: HeadersInit = {
		"Accept": "application/json",
	};

	// Add nonce for mutating requests if available.
	if (auth && getNonce()) {
		defaultHeaders["X-WP-Nonce"] = getNonce() as string;
	}

	// If there is a body, stringify & set Content-Type.
	let fetchBody: RequestInit["body"] = undefined;
	if (body !== undefined) {
		fetchBody = JSON.stringify(body);
		defaultHeaders["Content-Type"] = "application/json";
	}

	const response = await fetch(url, {
		credentials: "same-origin",
		headers: {
			...defaultHeaders,
			...headers,
		},
		body: fetchBody,
		...rest,
	});

	if (!response.ok) {
		// Attempt to parse error JSON but fallback to text.
		let message: unknown = undefined;
		try {
			message = await response.json();
		} catch (_) {
			message = await response.text();
		}

		throw new Error(`Request failed: ${response.status} ${response.statusText}. ${JSON.stringify(message)}`);
	}

	// 204 No Content – nothing to parse.
	if (response.status === 204) {
		return undefined as unknown as T;
	}

	return (await response.json()) as T;
}

// ─────────────────────────────────────────────────────────────────────────────
// Public helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch the Productbird settings option from `/wp/v2/settings`.
 */
export async function getSettings(): Promise<ProductbirdSettings> {
	const data = await request<{ productbird_settings: ProductbirdSettings }>(
		"wp/v2/settings",
	);

	return data.productbird_settings;
}

/**
 * Update one or more Productbird settings.
 */
export async function updateSettings(
	partial: Partial<ProductbirdSettings>,
): Promise<ProductbirdSettings> {
	const data = await request<{ productbird_settings: ProductbirdSettings }>(
		"wp/v2/settings",
		{
			method: "POST",
			body: { productbird_settings: partial },
		},
	);

	return data.productbird_settings;
}

/**
 * POST a list of Woo product IDs and receive their generation status.
 */
export async function checkGenerationStatus(
	productIds: number[],
): Promise<Record<number, GenerationStatus>> {
	return request<Record<number, GenerationStatus>>("productbird/v1/check-generation-status", {
		method: "POST",
		body: { productIds },
	});
}

// The module also re-exports the low-level request helper for advanced use.
export { request as rawRequest };
