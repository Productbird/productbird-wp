<script lang="ts">
  import { type PrimitiveElementAttributes, cn } from "$lib/utils/ui.js";

  import { Link } from "@wjfe/n-savant";
  import { routerConfig } from "../constants.js";

  let { class: className, ...restProps }: PrimitiveElementAttributes = $props();
</script>

<nav {...restProps} class={cn("flex items-center space-x-4 lg:space-x-6", className)}>
  {#each routerConfig as route}
    {#if !route.hidden}
      <Link
        class="hover:text-primary text-sm font-medium transition-colors"
        href={route.href}
        activeState={{
          key: route.key,
          class: "active",
        }}
      >
        {route.label}
      </Link>
    {/if}
  {/each}
</nav>
