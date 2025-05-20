<script lang="ts">
  import type { AdminSettingsFormComponenProps } from "$admin-settings/form-schema";
  import { __ } from "@wordpress/i18n";

  let { form }: AdminSettingsFormComponenProps = $props();

  const { form: formData, errors } = form;
</script>

<div>
  <h1>{__("Onboarding", "productbird")}</h1>
</div>
