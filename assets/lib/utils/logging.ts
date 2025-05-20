/**
 * Logging utilities
 */

/**
 * Log a message to the console if debug mode is enabled
 */
export function log(
	message: string,
	level: "log" | "error" | "warn" = "log",
	...args: unknown[]
): void {
	// Add prefix to message
	const prefixedMessage = `[Productbird] ${message}`;

	// Use the appropriate console method
	switch (level) {
		case "error":
			console.error(prefixedMessage, ...args);
			break;
		case "warn":
			console.warn(prefixedMessage, ...args);
			break;
		default:
			console.log(prefixedMessage, ...args);
	}
}
