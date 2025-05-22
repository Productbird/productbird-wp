import App from "./root.svelte";
import { createComponentMounter } from "$lib/utils/component-mounter";
import type { ComponentMounter } from "$lib/utils/component-mounter";

// -----------------------------------------------------------------------------
// 1. Create the mounter (but it will only mount once the placeholder element
//    appears in the DOM).
// -----------------------------------------------------------------------------

type RootProps = {
	selectedIds: number[];
};

const mounter: ComponentMounter<RootProps> = createComponentMounter<RootProps>({
	component: App,
	mountDelay: 0,
	selector: ".productbird-product-description-modal",
	getPropsFromElement: (element) => {
		const idsJson = element.dataset.selectedIds ?? "[]";
		let selectedIds: number[] = [];
		try {
			selectedIds = JSON.parse(idsJson);
		} catch {
			selectedIds = [];
		}
		return { selectedIds };
	},
	onMountSuccess(_app, element) {
		console.log("Mounted product description modal", element);
	},
	onMountError(error, element) {
		console.error("Failed to mount product description modal", error, element);
	},
});

// -----------------------------------------------------------------------------
// 2. Intercept the bulk-action form submit for our custom action and instead
//    open the modal.
// -----------------------------------------------------------------------------

function interceptBulkAction(): void {
	const form = document.getElementById(
		"posts-filter",
	) as HTMLFormElement | null;
	if (!form) return;

	form.addEventListener("submit", (event) => {
		const actionSelector = form.querySelector<HTMLSelectElement>(
			"select[name='action']",
		);
		const action = actionSelector?.value;

		if (action !== "productbird_generate_description") {
			return; // Let WordPress handle other actions normally.
		}

		// Prevent WordPress from submitting the form and refreshing the page.
		event.preventDefault();

		// Collect selected product IDs from the checkboxes.
		const checkboxes = form.querySelectorAll<HTMLInputElement>(
			"input[name='post[]']:checked",
		);
		const selectedIds = Array.from(checkboxes).map((cb) =>
			Number.parseInt(cb.value, 10),
		);

		if (selectedIds.length === 0) {
			alert(
				"Please select at least one product before running Productbird AI.",
			);
			return;
		}

		// Enforce the 250-item backend limit.
		interface ProductbirdGlobals {
			productbird_bulk?: {
				max_batch: number;
			};
		}
		const globals = window as unknown as ProductbirdGlobals;
		const maxBatch = globals.productbird_bulk?.max_batch ?? 250;

		if (selectedIds.length > maxBatch) {
			alert(
				`Productbird can only process up to ${maxBatch} products at once. Please select fewer products and try again.`,
			);
			return;
		}

		// Create (or reuse) the modal container.
		let container = document.querySelector<HTMLDivElement>(
			".productbird-product-description-modal",
		);
		if (!container) {
			container = document.createElement("div");
			container.className = "productbird-product-description-modal";
			document.body.appendChild(container);
		}

		// Pass the selected IDs via a data attribute for getPropsFromElement.
		container.dataset.selectedIds = JSON.stringify(selectedIds);

		// The mounter's MutationObserver will detect the new element and mount
		// automatically, but we can also request an explicit mount for speed.
		mounter.mountAllElements();
	});
}

// Wait for DOM ready in case this script is loaded in footer.
if (document.readyState === "loading") {
	document.addEventListener("DOMContentLoaded", interceptBulkAction);
} else {
	interceptBulkAction();
}

// Expose for debugging/tests
export default mounter.getMountedApps();
