<script lang="ts" module>
  import type { WithElementRef } from "bits-ui";
  import type { HTMLAnchorAttributes, HTMLButtonAttributes } from "svelte/elements";
  import { type VariantProps, tv } from "tailwind-variants";

  export type ButtonProps = WithElementRef<HTMLAnchorAttributes>;
</script>

<script lang="ts">
  import { cn } from "$lib/utils/ui.js";
  import { __ } from "@wordpress/i18n";
  import LogoIcon from "./logo-icon.svelte";

  let {
    class: className,

    ref = $bindable(null),
    href = undefined,
    type = "button",
    children,
    ...restProps
  }: ButtonProps = $props();
</script>

<a
  bind:this={ref}
  class={cn("productbird-connect-btn bg-primary text-primary-foreground", className)}
  {href}
  {...restProps}
>
  <LogoIcon animate={false} class="w-4 h-4 mr-2" />
  {__("Connect with Productbird", "productbird")}
</a>

<style>
  /* Social login button styling */
  .productbird-connect-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    padding: 10px 20px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    line-height: 1.5;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    width: auto;
  }

  .productbird-connect-btn:hover,
  .productbird-connect-btn:focus {
    background-color: #e05600;
    color: white;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
  }
</style>
