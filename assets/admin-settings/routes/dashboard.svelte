<script lang="ts">
  import * as Card from "$lib/components/ui/card/index.js";
  import * as Button from "$lib/components/ui/button/index.js";
  import { adminSettings } from "$admin-settings/admin-state.svelte.js";
  import { __, sprintf } from "@wordpress/i18n";
  import ConnectFlowButton from "$admin-settings/components/connect-flow-button.svelte";
  import { useGetOrganizations, useGetSettings } from "$lib/hooks/queries";
  import QueryWrapper from "$lib/components/query-wrapper.svelte";
  import type { AdminSettingsFormComponenProps } from "$admin-settings/form-schema";
  import * as Form from "$lib/components/ui/form/index.js";
  import * as Select from "$lib/components/ui/select/index.js";
  import { Badge } from "$lib/components/ui/badge";
  import PasswordInput from "$admin-settings/components/password-input.svelte";

  let { form }: AdminSettingsFormComponenProps = $props();

  const { form: formData } = form;

  const organizationsQuery = useGetOrganizations();
  const settingsQuery = useGetSettings();

  function getOrganizationName(id: string) {
    const organization = organizationsQuery.data?.find((organization) => organization.id.toString() === id);
    return organization?.name;
  }

  const organization = $derived.by(() => {
    const organization = organizationsQuery.data?.find(
      (organization) => organization.id.toString() === $formData.selected_org_id
    );

    return organization ?? null;
  });
</script>

<div class="grid gap-6">
  <!-- Current balance card -->
  <Card.Root class="max-w-md">
    <Card.Header>
      <Card.Title class="text-lg font-semibold">
        {sprintf(
          __("Hello %s 👋", "productbird"),
          adminSettings?.current_user
            ? (adminSettings.current_user.display_name ?? adminSettings.current_user.email)
            : ""
        )}
      </Card.Title>
    </Card.Header>

    {#if adminSettings.features.oidc}
      <Card.Content>
        {__("Your current credit balance", "productbird")}

        <p class="text-5xl font-bold">
          {#if organization}
            {organization.balance}
          {:else}
            -
          {/if}
        </p>
      </Card.Content>
    {:else if !settingsQuery.data?.api_key}
      <Card.Content>
        {__("You are not connected to Productbird. Please enter your API key to continue.", "productbird")}
      </Card.Content>
    {/if}

    <Card.Footer class="pt-2 flex gap-1.5 flex-row items-start">
      {#if organization}
        <Button.Root
          size="sm"
          variant="outline"
          target="_blank"
          href={`${adminSettings.app_url}/${organization.id}/billing`}
        >
          {__("View activity", "productbird")}
        </Button.Root>
      {:else if !settingsQuery.data?.api_key}
        <Button.Root size="sm" variant="default" target="_blank" href={`${adminSettings.app_url}`}>
          {__("Get started", "productbird")}
        </Button.Root>
      {/if}
    </Card.Footer>
  </Card.Root>

  <!-- Api key-->
  <Card.Root class="max-w-md">
    <Card.Header>
      <Card.Title class="text-lg font-semibold flex items-center gap-2">
        {__("API Key", "productbird")}
      </Card.Title>
      <Card.Description>
        {__("Your API key is used to authenticate your requests to the Productbird API.", "productbird")}
      </Card.Description>
    </Card.Header>

    <Card.Content>
      <Form.Field {form} name="api_key">
        <Form.Control>
          {#snippet children({ props })}
            <Form.Label>API Key</Form.Label>

            <PasswordInput
              {...props}
              autocomplete="off"
              data-lpignore="true"
              data-1p-ignore="true"
              bind:value={$formData.api_key}
              enableToggle
            />
          {/snippet}
        </Form.Control>
        <Form.Description>
          {__("You can manage your API key in the Productbird dashboard.", "productbird")}
          <a href={adminSettings.app_url} target="_blank">
            {__("Manage API key", "productbird")}
          </a>
        </Form.Description>
        <Form.FieldErrors />
      </Form.Field>
    </Card.Content>
  </Card.Root>

  {#if adminSettings.features.oidc}
    <Card.Root class="max-w-md">
      <Card.Header>
        <Card.Title class="text-lg font-semibold flex items-center gap-2">
          {__("Connection Status", "productbird")}
          <Badge variant="outline" class="text-sm text-gray-500">
            {__("Beta", "productbird")}
          </Badge>
        </Card.Title>
        <Card.Description>
          {__("Your connected Productbird account", "productbird")}
        </Card.Description>
      </Card.Header>

      <!-- Experimental OIDC connection -->
      <Card.Content>
        <QueryWrapper query={organizationsQuery}>
          {#snippet children({ data })}
            <Form.Field {form} name="selected_org_id">
              <Form.Control>
                {#snippet children({ props })}
                  <Form.Label>Organization</Form.Label>
                  <Select.Root type="single" bind:value={$formData.selected_org_id} name={props.name}>
                    <Select.Trigger {...props}>
                      {$formData.selected_org_id
                        ? getOrganizationName($formData.selected_org_id)
                        : "Select an organization"}
                    </Select.Trigger>
                    <Select.Content>
                      {#each data as organization}
                        <Select.Item value={organization.id.toString()} label={organization.name} />
                      {/each}
                    </Select.Content>
                  </Select.Root>
                {/snippet}
              </Form.Control>
              <Form.Description>
                {__("You can manage your organization in the Productbird dashboard.", "productbird")}
                <a href={adminSettings.app_url} target="_blank">
                  {__("Manage organization", "productbird")}
                </a>
              </Form.Description>
              <Form.FieldErrors />
            </Form.Field>
          {/snippet}
        </QueryWrapper>
      </Card.Content>

      <Card.Footer class="pt-2 flex gap-1.5 flex-row items-start">
        <ConnectFlowButton />
      </Card.Footer>
    </Card.Root>
  {/if}
</div>
