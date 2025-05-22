import type { AdminSettingsFormSchema } from "$admin-settings/form-schema";
import { Formality, Tone } from "./schemas";
import type { OrganizationMe } from "./types";

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

export type GenerationStatus = "none" | "running" | "completed" | "error";

// ─────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Try to obtain the WP nonce that authorises authenticated requests.
 */
function getNonce(): string | undefined {
	return window.productbird.nonce;
}

interface RequestOptions extends Omit<RequestInit, "body"> {
	/** JSON body that will be stringified automatically. */
	body?: unknown;
	/** Whether to automatically include the X-WP-Nonce header (default: true). */
	auth?: boolean;
}

async function request<T = unknown>(
	path: string,
	options: RequestOptions = {},
): Promise<T> {
	const { body, auth = true, headers, ...rest } = options;

	// Build full URL.
	const url = window.productbird.api_root_url + path;

	// Default headers.
	const defaultHeaders: HeadersInit = {
		Accept: "application/json",
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

		throw new Error(
			`Request failed: ${response.status} ${response.statusText}. ${JSON.stringify(message)}`,
		);
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
export async function getSettings(): Promise<AdminSettingsFormSchema> {
	const data = await request<AdminSettingsFormSchema>(
		"productbird/v1/settings",
	);

	return data;
}

/**
 * Fetch the Productbird organizations from `/productbird/v1/organizations/me`.
 */
export async function getOrganizations(): Promise<OrganizationMe[]> {
	const data = await request<OrganizationMe[]>("productbird/v1/organizations");

	return data;
}

/**
 * Update one or more Productbird settings.
 */
export async function updateSettings(
	partial: Partial<AdminSettingsFormSchema>,
): Promise<AdminSettingsFormSchema> {
	const data = await request<AdminSettingsFormSchema>(
		"productbird/v1/settings",
		{
			method: "POST",
			body: partial,
		},
	);

	return data;
}

/**
 * POST a list of Woo product IDs and receive their generation status.
 */
export async function checkGenerationStatus(
	productIds: number[],
): Promise<Record<number, GenerationStatus>> {
	return request<Record<number, GenerationStatus>>(
		"productbird/v1/check-generation-status",
		{
			method: "POST",
			body: { productIds },
		},
	);
}

/**
 * Clear all Productbird post meta from products.
 */
export async function clearProductMeta(): Promise<{
	success: boolean;
	cleared: number;
}> {
	return request<{ success: boolean; cleared: number }>(
		"productbird/v1/clear-product-meta",
		{
			method: "POST",
		},
	);
}

// The module also re-exports the low-level request helper for advanced use.
export { request as rawRequest };
