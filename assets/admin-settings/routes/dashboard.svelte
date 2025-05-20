<script lang="ts">
  import * as Card from "$lib/components/ui/card/index.js";
  import * as Button from "$lib/components/ui/button/index.js";
  import { adminSettings } from "$admin-settings/admin-state.svelte.js";
  import { __, sprintf } from "@wordpress/i18n";
  import ConnectFlowButton from "$admin-settings/components/connect-flow-button.svelte";
  import { useGetOrganizations } from "$lib/hooks/queries";
  import QueryWrapper from "$lib/components/query-wrapper.svelte";
  import type { AdminSettingsFormComponenProps } from "$admin-settings/form-schema";
  import * as Form from "$lib/components/ui/form/index.js";
  import * as Select from "$lib/components/ui/select/index.js";

  let { form }: AdminSettingsFormComponenProps = $props();

  const { form: formData, errors } = form;

  const organizationsQuery = useGetOrganizations();

  function getOrganizationName(id: string) {
    const organization = organizationsQuery.data?.find((organization) => organization.id.toString() === id);
    return organization?.name;
  }

  const organization = $derived.by(() => {
    const organization = organizationsQuery.data?.find(
      (organization) => organization.id.toString() === $formData.selectedOrgId
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
      <Card.Description>
        {__("Your current credit balance", "productbird")}
      </Card.Description>
    </Card.Header>

    <Card.Content>
      <p class="text-5xl font-bold">
        {#if organization}
          {organization.balance}
        {:else}
          -
        {/if}
      </p>
    </Card.Content>

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
      {/if}
    </Card.Footer>
  </Card.Root>

  <Card.Root class="max-w-md">
    <Card.Header>
      <Card.Title class="text-lg font-semibold">
        {__("Connection Status", "productbird")}
      </Card.Title>
      <Card.Description>
        {__("Your connected Productbird account", "productbird")}
      </Card.Description>
    </Card.Header>

    <Card.Content>
      <QueryWrapper query={organizationsQuery}>
        {#snippet children({ data })}
          <Form.Field {form} name="selectedOrgId">
            <Form.Control>
              {#snippet children({ props })}
                <Form.Label>Organization</Form.Label>
                <Select.Root type="single" bind:value={$formData.selectedOrgId} name={props.name}>
                  <Select.Trigger {...props}>
                    {$formData.selectedOrgId ? getOrganizationName($formData.selectedOrgId) : "Select an organization"}
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
              You can manage email address in your <a href="/examples/forms">email settings</a>.
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
</div>
