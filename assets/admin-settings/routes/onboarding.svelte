<script lang="ts">
  import { __ } from "@wordpress/i18n";
  import type { AdminSettingsFormComponenProps } from "$admin-settings/form-schema";

  let { form }: AdminSettingsFormComponenProps = $props();

  const { form: formData, errors } = form;
</script>

<div class="mx-auto max-w-4xl py-24">
  <div class="rounded-lg border p-8">
    <div class="mb-8 flex w-full items-center justify-center">
      <img src="https://placehold.co/100x100" alt="Productbird Logo" class="h-10 w-10 rounded-full" />
    </div>
  </div>
</div>
