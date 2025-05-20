import { dashboardFormSchema, onboardingFormSchema } from "./form-schema";
import Dashboard from "./routes/dashboard.svelte";
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
		key: "onboarding",
		label: "Onboarding",
		href: "#/onboarding",
		path: "/onboarding",
		component: Onboarding,
		schema: zod(onboardingFormSchema),
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
