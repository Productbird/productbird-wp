import { readable, writable } from "svelte/store";

// Mock for $app/stores navigating store
export const navigating = readable(null); // Or a more complex mock if needed

// Mock for $app/stores page store
export const page = writable({
	url: new URL("http://localhost"),
	params: {},
	route: { id: null },
	status: 200,
	error: null,
	data: {},
	form: undefined,
});
