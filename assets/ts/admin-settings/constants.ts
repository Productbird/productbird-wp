import { z } from "zod";
import { dashboardFormSchema, generalSettingsFormSchema } from "./form-schema";
import Dashboard from "./routes/dashboard.svelte";
import GeneralSettings from "./routes/general-settings.svelte";
import Onboarding from "./routes/onboarding.svelte";

import { zod } from "sveltekit-superforms/adapters";

export const routerConfig = [
	{
		key: "home",
		label: "Dashboard",
		href: "#/",
		path: "/",
		component: Dashboard,
		schema: zod(dashboardFormSchema),
		hidden: false,
	},
	{
		key: "settings",
		label: "Settings",
		href: "#/settings",
		path: "/settings",
		component: GeneralSettings,
		schema: zod(generalSettingsFormSchema),
		hidden: false,
	},
	{
		key: "onboarding",
		label: "Onboarding",
		href: "#/onboarding",
		path: "/onboarding",
		component: Onboarding,
		schema: zod(z.object({})),
		hidden: true,
	},
] as const;

export type Key = (typeof routerConfig)[number]["key"];

export function getRouteByKey(key: Key) {
	const route = routerConfig.find((route) => route.key === key);

	if (!route) {
		throw new Error(`Route with key ${key} not found`);
	}

	return route;
}
