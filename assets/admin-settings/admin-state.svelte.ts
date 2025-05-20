import { z } from "zod";

export const adminSettings = window.productbird_admin;

export const adminSettingsSchema = z.object({
	orgId: z.number().optional(),
});

export type AdminSettingsSchema = typeof adminSettingsSchema;
