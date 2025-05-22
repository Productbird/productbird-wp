import { adminSettings } from "$admin-settings/admin-state.svelte";
import {
	getOrganizations,
	getSettings,
	rawRequest,
	clearProductMeta,
} from "$lib/utils/api";
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

// Generate product descriptions in bulk
export function useGenerateProductDescriptionsBulk() {
	return createMutation(() => ({
		mutationFn: async ({
			productIds,
			mode,
		}: { productIds: number[]; mode: "auto-apply" | "review" }) => {
			return await rawRequest(
				"productbird/v1/generate-product-description/bulk",
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
export function useGetCompletedDescriptions(productIds: number[]) {
	return createQuery(() => ({
		queryKey: ["completed-descriptions", productIds],
		queryFn: async () => {
			if (!productIds.length) return { completed: [], remaining: 0 };

			return await rawRequest(
				`productbird/v1/description-completed?productIds=${productIds.join(",")}`,
			);
		},
		refetchInterval: 5000, // Poll every 5 seconds
		enabled: productIds.length > 0,
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
			return await rawRequest("productbird/v1/apply-product-description", {
				method: "POST",
				body: { productId, description },
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
