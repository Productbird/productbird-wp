import "$lib/styles/global.pcss";
import { createComponentMounter } from "$lib/utils/component-mounter";
import Root from "./root.svelte";

function initializeApp() {
	const mounter = createComponentMounter({
		component: Root,
		mountDelay: 0,
		selector: "#productbird-admin-settings",
		onMountSuccess(element) {
			console.log("Mounted admin settings", element);
		},
		onMountError(error, element) {
			console.error("Failed to mount admin settings", error, element);
		},
	});

	return mounter;
}

export default initializeApp();
