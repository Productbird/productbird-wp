<script lang="ts" module>
  import type { WithElementRef } from "bits-ui";
  import type { HTMLAnchorAttributes, HTMLButtonAttributes } from "svelte/elements";
  import { type VariantProps, tv } from "tailwind-variants";

  export const buttonVariants = tv({
    base: "focus-visible:ring-ring inline-flex items-center justify-center gap-2 whitespace-nowrap !rounded-md text-sm font-medium !border-solid transition-colors focus-visible:outline-none focus-visible:ring-1 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0",
    variants: {
      variant: {
        default: "!bg-primary !text-primary-foreground hover:!bg-primary/90 shadow",
        black: "!bg-black text-white hover:!bg-black/90 shadow",
        destructive:
          "!bg-destructive text-destructive-foreground !border-destructive hover:!bg-destructive/90 shadow-sm",
        outline: "!border-input !bg-background hover:!bg-accent hover:text-accent-foreground !border !shadow-sm",
        secondary: "!bg-secondary text-secondary-foreground hover:!bg-secondary/80 shadow-sm",
        ghost: "hover:!bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline !border-none !shadow-none",
      },
      size: {
        default: "!h-9 !px-4 !py-2",
        sm: "!h-8 !px-3 !text-xs",
        lg: "!h-10  !px-8",
        icon: "!h-9 !w-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  });

  export type ButtonVariant = VariantProps<typeof buttonVariants>["variant"];
  export type ButtonSize = VariantProps<typeof buttonVariants>["size"];

  export type ButtonProps = WithElementRef<HTMLButtonAttributes> &
    WithElementRef<HTMLAnchorAttributes> & {
      loading?: boolean;
      variant?: ButtonVariant;
      size?: ButtonSize;
    };
</script>

<script lang="ts">
  import { cn } from "$lib/utils/ui.js";
  import { Pulse } from "../pulse";

  let {
    class: className,
    variant = "default",
    size = "default",
    ref = $bindable(null),
    href = undefined,
    type = "button",
    children,
    loading = false,
    ...restProps
  }: ButtonProps = $props();
</script>

{#if href}
  <a bind:this={ref} class={cn(buttonVariants({ variant, size }), className)} {href} {...restProps}>
    {@render children?.()}

    {#if loading}
      <Pulse class="ml-2" />
    {/if}
  </a>
{:else}
  <button bind:this={ref} class={cn(buttonVariants({ variant, size }), className)} {type} {...restProps}>
    {@render children?.()}

    {#if loading}
      <Pulse class="ml-2" />
    {/if}
  </button>
{/if}
