<script lang="ts">
  import * as Card from "$lib/components/ui/card/index.js";
  import { __ } from "@wordpress/i18n";
  import type { AdminSettingsFormComponenProps } from "$admin-settings/form-schema";
  import * as Form from "$lib/components/ui/form/index.js";
  import * as Select from "$lib/components/ui/select/index.js";
  import { getEnumValues, getLabelFromValue } from "$lib/utils/formatters";
  import { Formality, Tone } from "$lib/utils/schemas";

  let { form }: AdminSettingsFormComponenProps = $props();

  const { form: formData } = form;

  const toneOptions = getEnumValues(Tone);
  const formalityOptions = getEnumValues(Formality);
</script>

<div class="grid gap-6">
  <Card.Root class="max-w-md">
    <Card.Header>
      <Card.Title class="text-lg font-semibold">
        {__("Global Settings", "productbird")}
      </Card.Title>
      <Card.Description>
        {__("These global settings will be used for all your tools.", "productbird")}
      </Card.Description>
    </Card.Header>

    <Card.Content class="grid grid-cols gap-4">
      <Form.Field {form} name="tone">
        <Form.Control>
          {#snippet children({ props })}
            <Form.Label>{__("Tone", "productbird")}</Form.Label>
            <Select.Root type="single" bind:value={$formData.tone} name={props.name}>
              <Select.Trigger {...props}>
                {$formData.tone ? getLabelFromValue(Tone, $formData.tone) : "Select a tone"}
              </Select.Trigger>
              <Select.Content>
                {#each toneOptions as tone}
                  <Select.Item value={tone.value} label={tone.label} />
                {/each}
              </Select.Content>
            </Select.Root>
          {/snippet}
        </Form.Control>
        <Form.Description>
          {__("Select the tone of the generated content.", "productbird")}
        </Form.Description>
        <Form.FieldErrors />
      </Form.Field>

      <Form.Field {form} name="formality">
        <Form.Control>
          {#snippet children({ props })}
            <Form.Label>{__("Formality", "productbird")}</Form.Label>
            <Select.Root type="single" bind:value={$formData.formality} name={props.name}>
              <Select.Trigger {...props}>
                {$formData.formality ? getLabelFromValue(Formality, $formData.formality) : "Select a formality"}
              </Select.Trigger>
              <Select.Content>
                {#each formalityOptions as formality}
                  <Select.Item value={formality.value} label={formality.label} />
                {/each}
              </Select.Content>
            </Select.Root>
          {/snippet}
        </Form.Control>
        <Form.Description>
          {__("Select the formality of the generated content.", "productbird")}
        </Form.Description>
        <Form.FieldErrors />
      </Form.Field>
    </Card.Content>
  </Card.Root>
</div>
