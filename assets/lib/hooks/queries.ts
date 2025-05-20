import { adminSettings } from "$admin-settings/admin-state.svelte";
import { getOrganizations } from "$lib/utils/api";
import { createQuery } from "@tanstack/svelte-query";

export function useGetOrganizations() {
	return createQuery(() => ({
		queryKey: ["organizations"],
		queryFn: async () => await getOrganizations(),
		enabled: adminSettings.oidc.is_connected,
	}));
}
