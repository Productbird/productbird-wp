import Dashboard from "./routes/dashboard.svelte";
import Onboarding from "./routes/onboarding.svelte";

export const routerConfig = [
	{
		key: "home",
		label: "Home",
		href: "#/",
		path: "/",
		component: Dashboard,
		hidden: false,
	},
	{
		key: "onboarding",
		label: "Onboarding",
		href: "#/onboarding",
		path: "/onboarding",
		component: Onboarding,
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
