<script lang="ts">
  import { superForm } from "sveltekit-superforms/client";
  import * as Alert from "$lib/components/ui/alert/index.js";
  import ExclamationTriangle from "@lucide/svelte/icons/triangle-alert";
  import { __, sprintf } from "@wordpress/i18n";
  import * as Form from "$lib/components/ui/form/index.js";
  import { log } from "$lib/utils/logging";
  import { routerConfig } from "$admin-settings/constants";
  import { Route, Fallback, location } from "@wjfe/n-savant";
  import NotFound from "$admin-settings/routes/not-found.svelte";
  import { updateSettings } from "$lib/utils/api";
  import type { AdminSettingsFormSchema } from "$admin-settings/form-schema";
  import { createMutation, useQueryClient } from "@tanstack/svelte-query";
  import { toast } from "svelte-sonner";

  let { initialData }: { initialData: AdminSettingsFormSchema } = $props();

  let isProcessing = $state(false);
  let errorMessage = $state("");
  let showToast = $state(false);
  let toastMessage = $state("");

  const queryClient = useQueryClient();

  const updateSettingsMutation = createMutation(() => ({
    mutationFn: updateSettings,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
    },
  }));

  const currentValidatorBasedOnRoute = $derived(
    routerConfig.find((route) => location.hashPaths.single === route.path)?.schema
  );

  $effect(() => {
    if (currentValidatorBasedOnRoute) {
      options.validators = currentValidatorBasedOnRoute;
    } else {
      console.warn("No validator found for current route", location.hashPaths.single);
    }
  });

  // Initialize Superform
  const {
    reset,
    enhance,
    validateForm,
    allErrors,
    submitting,
    form: formData,
    options,
    ...superFormProps
  } = superForm(initialData, {
    SPA: true,
    dataType: "json",
    invalidateAll: false,
    applyAction: false,
    taintedMessage: __("Weet je zeker dat je het formulier wilt verlaten?"),
    onSubmit: async () => {
      /**
       * Validate the final form
       */
      const result = await validateForm({
        update: true,

        schema: currentValidatorBasedOnRoute,
      });

      if (result.valid) {
        try {
          isProcessing = true;

          log("[onSubmit] submitting form", "log", $formData);

          /**
           * After saving do not show this message when moving away.
           */
          options.taintedMessage = false;

          const result = await updateSettingsMutation.mutateAsync($formData);

          /**
           * IMPORTANT: This setTimeout MUST remain in place.
           *
           * SuperForms has internal state updates that occur in the microtask queue after form submission.
           * Using tick() or queueMicrotask() doesn't work because they also run in the microtask queue,
           * before SuperForms has finished its updates. setTimeout(0) ensures our reset runs in a new
           * macrotask, after all microtasks (including SuperForms' internal state updates) have completed.
           *
           * Removing this setTimeout or replacing it with tick()/queueMicrotask will cause form reset issues.
           */
          setTimeout(() => {
            reset({
              data: result,
              newState: result,
            });

            toast.success(__("Settings saved", "productbird"));
          }, 0);
        } catch (error) {
          console.error("Error saving form state:", error);
        } finally {
          isProcessing = false;
        }
      }
    },
    onError(event) {
      console.error("SuperForm Error:", event);
    },
  });

  const formProps = $derived({
    reset,
    enhance,
    validateForm,
    allErrors,
    form: formData,
    submitting,
    options,
    ...superFormProps,
  });
</script>

{#snippet errorDisplay()}
  {@const errorCount = $allErrors.length}

  {#if errorCount > 0}
    <Alert.Root variant="destructive" class="mt-4">
      <ExclamationTriangle class="h-4 w-4" />

      <Alert.Title>
        {#if errorCount === 1}
          {sprintf(__("Er is %s fout in het formulier", "ma"), errorCount)}
        {:else}
          {sprintf(__("Er zijn %s fouten in het formulier", "ma"), errorCount)}
        {/if}
      </Alert.Title>

      <Alert.Description>
        <ul class="ml-4 list-disc">
          {#each $allErrors as { messages }}
            {#each messages as message}
              <li>{message}</li>
            {/each}
          {/each}
        </ul>
      </Alert.Description>
    </Alert.Root>
  {/if}

  {#if errorMessage}
    <Alert.Root variant="destructive" class="mt-4">
      <ExclamationTriangle class="h-4 w-4" />
      <Alert.Title>{__("Er is een fout opgetreden", "ma")}</Alert.Title>
      <Alert.Description>{errorMessage}</Alert.Description>
    </Alert.Root>
  {/if}

  {#if showToast}
    <Alert.Root class="mt-4 bg-green-100 border-green-500">
      <Alert.Title>{toastMessage}</Alert.Title>
    </Alert.Root>
  {/if}
{/snippet}

<form method="POST" use:enhance class="space-y-6">
  {@render errorDisplay()}

  {#each routerConfig as { key, path, component: Component }}
    <Route {key} {path}>
      <Component form={formProps} />
    </Route>
  {/each}

  <Fallback>
    <NotFound />
  </Fallback>

  <!-- Navigation buttons -->
  <div class="flex pt-4">
    <Form.Button variant="black" disabled={$submitting || isProcessing}>
      {$submitting || isProcessing ? __("Saving...", "productbird") : __("Save settings", "productbird")}
    </Form.Button>
  </div>
</form>
