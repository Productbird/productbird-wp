import { adminSettings } from "$admin-settings/admin-state.svelte";
import {
	getOrganizations,
	getSettings,
	rawRequest,
	clearProductMeta,
} from "$lib/utils/api";
import type {
	MagicDescriptionsBulkWpJsonResponse,
	MagicDescriptionsStatusCheckWpJsonResponse,
	ProductId,
} from "$lib/utils/types";
import { createQuery, createMutation } from "@tanstack/svelte-query";
import { toast } from "svelte-sonner";

export function useGetOrganizations() {
	return createQuery(() => ({
		queryKey: ["organizations"],
		queryFn: async () => await getOrganizations(),
		enabled: adminSettings.oidc.is_connected || adminSettings.features.oidc,
	}));
}

export function useGetSettings() {
	return createQuery(() => ({
		queryKey: ["settings"],
		queryFn: async () => await getSettings(),
	}));
}

export function useGenerateMagicDescriptionsBulk() {
	return createMutation(() => ({
		mutationFn: async ({
			productIds,
			mode,
		}: { productIds: number[]; mode: "auto-apply" | "review" }) => {
			return await rawRequest<MagicDescriptionsBulkWpJsonResponse>(
				"productbird/v1/magic-descriptions/bulk",
				{
					method: "POST",
					body: { productIds, mode },
				},
			);
		},
		onError: (error) => {
			toast.error(error.message);
		},
	}));
}

// Poll for completed descriptions
export function usePollMagicDescriptionStatus(
	productIds: number[],
	enabled: boolean,
) {
	return createQuery<MagicDescriptionsStatusCheckWpJsonResponse>(() => ({
		queryKey: ["magic-descriptions-status", productIds],
		queryFn: async () => {
			if (!productIds.length)
				return { completed_items: [], remaining_count: 0 };

			// Ensure product IDs are properly formatted
			const formattedIds = productIds.map((id) => String(id));

			return await rawRequest<MagicDescriptionsStatusCheckWpJsonResponse>(
				`productbird/v1/magic-descriptions/status?product_ids=${formattedIds.join(",")}`,
			);
		},
		refetchInterval: enabled ? 5000 : false, // Poll every 5 seconds when enabled
		enabled,
	}));
}

// Regenerate a specific product description
export function useRegenerateProductDescription() {
	return createMutation(() => ({
		mutationFn: async ({
			productId,
			customPrompt,
		}: { productId: number; customPrompt?: string }) => {
			return await rawRequest("productbird/v1/regenerate", {
				method: "PUT",
				body: { productId, customPrompt },
			});
		},
	}));
}

// Apply a generated description to a product
export function useApplyProductDescription() {
	return createMutation(() => ({
		mutationFn: async ({
			productId,
			description,
		}: { productId: number; description: string }) => {
			return await rawRequest("productbird/v1/magic-descriptions/apply", {
				method: "POST",
				body: { productId, description },
			});
		},
	}));
}

// Decline a generated description for a product
export function useDeclineProductDescription() {
	return createMutation(() => ({
		mutationFn: async ({ productId }: { productId: number }) => {
			return await rawRequest("productbird/v1/magic-descriptions/decline", {
				method: "POST",
				body: { productId },
			});
		},
	}));
}

// Clear all Productbird post meta
export function useClearProductMeta() {
	return createMutation(() => ({
		mutationFn: async () => {
			return await clearProductMeta();
		},
		onSuccess: (data) => {
			toast.success(`${data.cleared} meta fields cleared`);
		},
	}));
}
