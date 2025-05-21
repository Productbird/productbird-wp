import { z } from "zod";
import type { SuperForm } from "sveltekit-superforms";
import { __ } from "@wordpress/i18n";
import { Formality, Tone } from "$lib/utils/schemas";

// Page: Dashboard (Home)
export const dashboardFormSchema = z.object({
	selected_org_id: z.string().optional(),
	api_key: z.string().optional(),
	webhook_secret: z.string().optional(),
});

// Page: General Settings (extends Dashboard)
export const generalSettingsFormSchema = dashboardFormSchema.extend({
	tone: z.nativeEnum(Tone),
	formality: z.nativeEnum(Formality),
});

export const adminSettingsFormSchema = generalSettingsFormSchema;
export type AdminSettingsFormSchema = z.infer<typeof adminSettingsFormSchema>;

type SuperformAdminSettingsForm = SuperForm<AdminSettingsFormSchema>;

export type AdminSettingsFormComponenProps = {
	form: SuperformAdminSettingsForm;
};

export type AdminSettingsFormPageProps<T extends z.ZodTypeAny> = {
	form: SuperForm<z.infer<T>>;
};
