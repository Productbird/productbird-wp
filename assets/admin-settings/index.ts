import App from "./admin-settings.svelte";
import { createComponentMounter } from "$lib/utils/component-mounter";
import "$lib/styles/global.css";

const mounter = createComponentMounter({
	component: App,
	mountDelay: 0,
	selector: "#productbird-admin-settings",
	onMountSuccess(element) {
		console.log("Mounted admin settings", element);
	},
	onMountError(error, element) {
		console.error("Failed to mount admin settings", error, element);
	},
});

export default mounter.getMountedApps();
