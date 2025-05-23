import "$lib/styles/global.pcss";
import App from "./root.svelte";
import { createComponentMounter } from "$lib/utils/component-mounter";

function initializeApp() {
	const mounter = createComponentMounter({
		component: App,
		mountDelay: 0,
		selector: "#productbird-magic-descriptions-app",
		onMountSuccess(app, element) {
			console.log("Mounted magic descriptions app", element);
		},
		onMountError(error, element) {
			console.error("Failed to mount magic descriptions app", error, element);
		},
	});

	return mounter;
}

export default initializeApp();
