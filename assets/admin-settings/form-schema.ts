import { z } from "zod";
import type { SuperForm } from "sveltekit-superforms";
import { __ } from "@wordpress/i18n";

export const onboardingFormSchema = z.object({
	selectedOrgId: z
		.number()
		.min(1, __("Organization is required", "productbird"))
		.optional(),
});

// Page: Dashboard (Home)
export const dashboardFormSchema = z.object({
	selectedOrgId: z
		.string()
		.min(1, __("Organization is required", "productbird"))
		.optional(),
});

// Page: General Settings (extends Dashboard)
export const generalSettingsFormSchema = dashboardFormSchema.extend({
	tone: z.string().min(1, __("Tone is required", "productbird")).optional(),
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
