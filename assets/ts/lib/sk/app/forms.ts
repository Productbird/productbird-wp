import * as devalue from "devalue";

// biome-ignore lint/suspicious/noExplicitAny: <explanation>
export const applyAction = (result: any) => {
	console.log("applyAction", { result });

	// if (result.type === 'error') {
	//     const url = new URL(location.href);
	//     const { branch, route } = current;
	//     if (!route) return;
	//     const error_load = await load_nearest_error_page(
	//         current.branch.length,
	//         branch,
	//         route.errors,
	//     );
	//     if (error_load) {
	//         const navigation_result = await get_navigation_result_from_branch({
	//             url,
	//             params: current.params,
	//             branch: branch.slice(0, error_load.idx).concat(error_load.node),
	//             status: result.status ?? 500,
	//             error: result.error,
	//             route,
	//         });
	//         current = navigation_result.state;
	//         root.$set(navigation_result.props);
	//         tick().then(reset_focus);
	//     }
	// } else if (result.type === 'redirect') {
	//     _goto(result.location, { invalidateAll: true }, 0);
	// } else {
	//     /** @type {Record<string, any>} */
	//     root.$set({
	//         // this brings Svelte's view of the world in line with SvelteKit's
	//         // after use:enhance reset the form....
	//         form: null,
	//         page: { ...page, form: result.data, status: result.status },
	//     });
	//     // ...so that setting the `form` prop takes effect and isn't ignored
	//     await tick();
	//     root.$set({ form: result.data });
	//     if (result.type === 'success') {
	//         reset_focus();
	//     }
	// }
};

function clone<T extends HTMLElement>(element: T): T {
	return HTMLElement.prototype.cloneNode.call(element) as T;
}
export function deserialize(result: string) {
	const parsed = JSON.parse(result);
	if (parsed.data) {
		parsed.data = devalue.parse(parsed.data);
	}
	return parsed;
}

export function enhance(
	form_element: HTMLFormElement | undefined,
	submit = () => Promise<void>,
) {
	const fallback_callback = ({
		action,
		result,
		reset = true,
		invalidateAll: shouldInvalidateAll = true,
		// biome-ignore lint/suspicious/noExplicitAny: <explanation>
	}: any) => {
		if (result.type === "success") {
			if (reset) {
				// We call reset from the prototype to avoid DOM clobbering
				HTMLFormElement.prototype.reset.call(form_element);
			}
			if (shouldInvalidateAll) {
				// await invalidateAll();
			}
		}

		// For success/failure results, only apply action if it belongs to the
		// current page, otherwise `form` will be updated erroneously
		if (
			location.origin + location.pathname === action.origin + action.pathname ||
			result.type === "redirect" ||
			result.type === "error"
		) {
			applyAction(result);
		}
	};

	/** @param {SubmitEvent} event */
	async function handle_submit(event: {
		submitter: {
			// biome-ignore lint/suspicious/noExplicitAny: <explanation>
			hasAttribute: (arg0: string) => any;
			// biome-ignore lint/suspicious/noExplicitAny: <explanation>
			formMethod: any;
			formAction: string | URL;
			// biome-ignore lint/suspicious/noExplicitAny: <explanation>
			getAttribute: (arg0: string) => any;
		};
		preventDefault: () => void;
	}) {
		const method = event.submitter?.hasAttribute("formmethod")
			? /** @type {HTMLButtonElement | HTMLInputElement} */ event.submitter
					.formMethod
			: // @ts-expect-error -- Whatever
				clone(form_element).method;
		if (method !== "post") return;

		event.preventDefault();

		const action = new URL(
			// We can't do submitter.formAction directly because that property is always set
			event.submitter?.hasAttribute("formaction")
				? /** @type {HTMLButtonElement | HTMLInputElement} */ event.submitter
						.formAction
				: // @ts-expect-error -- Whatever
					clone(form_element).action,
		);

		const form_data = new FormData(form_element);

		// if (DEV && clone(form_element).enctype !== 'multipart/form-data') {
		//     for (const value of form_data.values()) {
		//         if (value instanceof File) {
		//             throw new Error(
		//                 'Your form contains <input type="file"> fields, but is missing the necessary `enctype="multipart/form-data"` attribute. This will lead to inconsistent behavior between enhanced and native forms. For more details, see https://github.com/sveltejs/kit/issues/9819.',
		//             );
		//         }
		//     }
		// }

		const submitter_name = event.submitter?.getAttribute("name");
		if (submitter_name) {
			form_data.append(
				submitter_name,
				event.submitter?.getAttribute("value") ?? "",
			);
		}

		const controller = new AbortController();

		let cancelled = false;
		const cancel = () => {
			cancelled = true;
		};

		const callback =
			// @ts-ignore
			// eslint-disable-next-line
			(await submit({
				action,
				cancel,
				controller,
				formData: form_data,
				formElement: form_element,
				submitter: event.submitter,
			})) ?? fallback_callback;
		if (cancelled) return;

		/** @type {import('@sveltejs/kit').ActionResult} */
		// biome-ignore lint/suspicious/noExplicitAny: <explanation>
		let result: any;

		try {
			const response = await fetch(action, {
				method: "POST",
				headers: {
					accept: "application/json",
					"x-sveltekit-action": "true",
				},
				cache: "no-store",
				body: form_data,
				signal: controller.signal,
			});

			result = deserialize(await response.text());
			if (result.type === "error") result.status = response.status;
		} catch (error) {
			// biome-ignore lint/suspicious/noExplicitAny: <explanation>
			if ((error as any)?.name === "AbortError") return;
			result = { type: "error", error };
		}

		// @ts-ignore
		callback({
			action,
			formData: form_data,
			formElement: form_element,
			update: (opts: { reset: any; invalidateAll: any }) =>
				fallback_callback({
					action,
					result,
					reset: opts?.reset,
					invalidateAll: opts?.invalidateAll,
				}),
			result,
		});
	}

	HTMLFormElement.prototype.addEventListener.call(
		form_element,
		"submit",
		// @ts-expect-error -- Whatever
		handle_submit,
	);

	return {
		destroy() {
			HTMLFormElement.prototype.removeEventListener.call(
				form_element,
				"submit",
				// @ts-expect-error -- Whatever
				handle_submit,
			);
		},
	};
}
