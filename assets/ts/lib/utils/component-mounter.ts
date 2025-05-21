import { mount } from "svelte";
import type { Component } from "svelte";

export type MountOptions<
	Props extends Record<string, unknown> = Record<string, unknown>,
> = {
	component: Component<Props>;
	selector: string;
	elementorPopupSupport?: boolean;
	mountDelay?: number;
	elementorPopupDelay?: number;
	getPropsFromElement?: (element: HTMLElement) => Props;
	onMountSuccess?: (
		app: ReturnType<typeof mount>,
		element: HTMLElement,
	) => void;
	onMountError?: (error: unknown, element: HTMLElement) => void;
};

/**
 * A utility for mounting Svelte components to DOM elements with support for:
 * - Initial mounting with delay
 * - Elementor popup support
 * - MutationObserver for dynamic content
 * - Preventing double-mounting
 */
export class ComponentMounter<
	Props extends Record<string, unknown> = Record<string, unknown>,
> {
	private apps: ReturnType<typeof mount>[] = [];
	private bodyObserver: MutationObserver | null = null;
	private options: MountOptions<Props>;

	constructor(options: MountOptions<Props>) {
		this.options = {
			mountDelay: 300,
			elementorPopupDelay: 200,
			elementorPopupSupport: true,
			...options,
		};

		// Initial mount with a delay to ensure DOM is ready
		this.mountAllElements();

		// Set up event listeners if Elementor popup support is enabled
		if (this.options.elementorPopupSupport) {
			this.setupElementorPopupSupport();
		}

		// Set up MutationObserver to detect dynamically added elements
		this.setupMutationObserver();
	}

	/**
	 * Mount components on all matching elements
	 */
	public mountAllElements(): void {
		setTimeout(() => {
			const elements = document.querySelectorAll<HTMLElement>(
				this.options.selector,
			);

			if (elements.length > 0) {
				const newApps = this.mountComponentsOnElements(elements);
				this.apps = [...this.apps, ...newApps];
			}
		}, this.options.mountDelay);
	}

	/**
	 * Mount components on specific elements
	 */
	private mountComponentsOnElements(
		elements: NodeListOf<HTMLElement>,
	): ReturnType<typeof mount>[] {
		const mountedApps: ReturnType<typeof mount>[] = [];

		for (const element of elements) {
			// Skip if element already has a mounted component
			if (element.dataset.mounted === "true") {
				continue;
			}

			// Get props from element using the provided function or empty object
			const props = this.options.getPropsFromElement
				? this.options.getPropsFromElement(element)
				: ({} as Props);

			// Mount the Svelte component with props
			try {
				const app = mount(this.options.component, {
					target: element,
					props,
				});

				// Mark element as mounted
				element.dataset.mounted = "true";

				// Add to apps array
				mountedApps.push(app);

				// Call onMountSuccess callback if provided
				if (this.options.onMountSuccess) {
					this.options.onMountSuccess(app, element);
				}
			} catch (error) {
				console.error("Error mounting component:", error);

				// Call onMountError callback if provided
				if (this.options.onMountError) {
					this.options.onMountError(error, element);
				}
			}
		}

		return mountedApps;
	}

	/**
	 * Set up support for Elementor popups
	 */
	private setupElementorPopupSupport(): void {
		document.addEventListener("elementor/popup/show", (event) => {
			// The event doesn't have standard properties, so we need to extract parameters differently
			// @ts-ignore - Elementor-specific event
			const [_, id, instance] = event.detail || [];

			// Check if popup contains our target elements
			const popupElement = instance?.$element?.[0];
			if (popupElement) {
				// Small delay to ensure Elementor has finished rendering the popup content
				setTimeout(() => {
					const popupElements = popupElement.querySelectorAll<HTMLElement>(
						this.options.selector,
					);

					if (popupElements.length > 0) {
						// Mount components on these new elements
						const newApps = this.mountComponentsOnElements(popupElements);

						// Add these to our apps array
						this.apps = [...this.apps, ...newApps];
					}
				}, this.options.elementorPopupDelay);
			}
		});
	}

	/**
	 * Set up a MutationObserver to detect dynamically added elements
	 */
	private setupMutationObserver(): void {
		this.bodyObserver = new MutationObserver((mutations) => {
			let shouldMount = false;

			for (const mutation of mutations) {
				if (mutation.type === "childList" && mutation.addedNodes.length > 0) {
					// Check if any of the added nodes contain our target elements
					for (const node of mutation.addedNodes) {
						if (node.nodeType === Node.ELEMENT_NODE) {
							const element = node as Element;
							if (
								element.matches(this.options.selector) ||
								element.querySelector(this.options.selector)
							) {
								shouldMount = true;
							}
						}
					}
				}
			}

			if (shouldMount) {
				this.mountAllElements();
			}
		});

		// Start observing the body for changes
		this.bodyObserver.observe(document.body, {
			childList: true,
			subtree: true,
		});
	}

	/**
	 * Cleanup method to disconnect observers and unmount components
	 */
	public destroy(): void {
		// Disconnect the observer
		if (this.bodyObserver) {
			this.bodyObserver.disconnect();
		}

		// Unmount all apps
		for (const app of this.apps) {
			if (typeof app.$destroy === "function") {
				app.$destroy();
			}
		}

		this.apps = [];
	}

	/**
	 * Get all mounted apps
	 */
	public getMountedApps(): ReturnType<typeof mount>[] {
		return this.apps;
	}
}

/**
 * Helper function to create a component mounter instance
 */
export function createComponentMounter<
	Props extends Record<string, unknown> = Record<string, unknown>,
>(options: MountOptions<Props>): ComponentMounter<Props> {
	return new ComponentMounter<Props>(options);
}
