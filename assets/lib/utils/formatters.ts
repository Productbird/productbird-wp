export function getEnumValues<T extends { [key: string]: string }>(
	enumObj: T,
): { label: string; value: string }[] {
	return Object.entries(enumObj).map(([key, value]) => ({
		label: key,
		value: value,
	}));
}

export function getLabelFromValue<T extends { [key: string]: string }>(
	enumObj: T,
	value: string,
): string {
	return Object.entries(enumObj).find(([_, val]) => val === value)?.[0] ?? "";
}
