import "$lib/styles/global.pcss";
import App, { type RootProps } from "./root.svelte";
import { createComponentMounter } from "$lib/utils/component-mounter";
import type { ComponentMounter } from "$lib/utils/component-mounter";
import { PRODUCT_DESCRIPTION_GLOBALS } from "./utils";
// -----------------------------------------------------------------------------
// 1. Create the mounter (but it will only mount once the placeholder element
//    appears in the DOM).
// -----------------------------------------------------------------------------

const mounter: ComponentMounter<RootProps> = createComponentMounter<RootProps>({
	component: App,
	mountDelay: 0,
	selector: ".productbird-magic-descriptions-modal",
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

		if (action !== "productbird_magic_descriptions") {
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

		const maxBatch = PRODUCT_DESCRIPTION_GLOBALS.max_batch;

		if (selectedIds.length > maxBatch) {
			alert(
				`Productbird can only process up to ${maxBatch} products at once. Please select fewer products and try again.`,
			);

			return;
		}

		// Create (or reuse) the modal container.
		let container = document.querySelector<HTMLDivElement>(
			".productbird-magic-descriptions-modal",
		);
		console.log("container", container);
		if (!container) {
			container = document.createElement("div");
			// add [data-productbird-app]
			container.dataset.productbirdApp = "true";
			container.className = "productbird-magic-descriptions-modal";
			document.body.appendChild(container);
		}

		// Pass the selected IDs via a data attribute for getPropsFromElement.
		container.dataset.selectedIds = JSON.stringify(selectedIds);

		// The mounter's MutationObserver will detect the new element and mount
		// automatically, but we can also request an explicit mount for speed.
		mounter.mountAllElements();
	});
}

// -----------------------------------------------------------------------------
// 3. Disable the bulk action group label in the dropdown.
// -----------------------------------------------------------------------------
function disableBulkActionGroupOption(): void {
	const labelToDisable = PRODUCT_DESCRIPTION_GLOBALS.bulk_action_group_label;

	const selectors = ['select[name="action"]', 'select[name="action2"]'];
	for (const selector of selectors) {
		const selectElements =
			document.querySelectorAll<HTMLSelectElement>(selector);

		console.log(selectElements);

		for (const selectElement of selectElements) {
			console.log(`${selector} ${labelToDisable}`);
			const optionToDisable = selectElement.querySelector<HTMLOptionElement>(
				`option[value="${labelToDisable}"]`,
			);

			console.log(optionToDisable);

			if (optionToDisable) {
				optionToDisable.disabled = true;
				optionToDisable.style.color = "#999";
				optionToDisable.style.fontStyle = "italic";
			}
		}
	}
}

if (document.readyState === "loading") {
	document.addEventListener("DOMContentLoaded", () => {
		interceptBulkAction();
		disableBulkActionGroupOption();
	});
} else {
	interceptBulkAction();
	disableBulkActionGroupOption();
}

// Expose for debugging/tests
export default mounter.getMountedApps();
