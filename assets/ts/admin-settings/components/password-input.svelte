<script lang="ts">
  import { Button } from "$lib/components/ui/button/index.js";
  import { Input, type Props as InputProps } from "$lib/components/ui/input/index.js";
  import { cn } from "$lib/utils/ui.js";
  import { Eye, EyeOff } from "@lucide/svelte";

  type $$Props = InputProps & {
    enableToggle?: boolean;
    class?: string | undefined | null;
    value?: string | number | null | undefined;
  };

  let { class: className, enableToggle = false, value = $bindable(""), ...restProps }: $$Props = $props();

  let isVisible = $state(false);
  let hasValue = $state(!!value);

  $effect(() => {
    hasValue = !!value;
  });

  function handleChange(event: Event) {
    const target = event.target as HTMLInputElement;
    hasValue = !!target.value;
  }
</script>

<div class="relative">
  <Input
    class={cn(enableToggle && "pr-10", className)}
    type={isVisible && enableToggle ? "text" : "password"}
    bind:value
    oninput={handleChange}
    {...restProps}
  />

  {#if enableToggle}
    <Button
      class="absolute right-0 top-0 hover:bg-transparent"
      disabled={!hasValue}
      size="icon"
      type="button"
      variant="ghost"
      onclick={() => (isVisible = !isVisible)}
    >
      {#if isVisible}
        <Eye />
      {:else}
        <EyeOff />
      {/if}
    </Button>

    <!-- Style to hide default password toggle in Edge/IE -->
    <style>
      input::-ms-reveal,
      input::-ms-clear {
        visibility: hidden;
        pointer-events: none;
        display: none;
      }
    </style>
  {/if}
</div>
