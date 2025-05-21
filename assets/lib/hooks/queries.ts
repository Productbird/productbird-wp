import { adminSettings } from "$admin-settings/admin-state.svelte";
import { getOrganizations, getSettings } from "$lib/utils/api";
import { createQuery } from "@tanstack/svelte-query";

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
