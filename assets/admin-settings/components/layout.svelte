<script lang="ts">
  import type { Snippet } from "svelte";
  import Nav from "./nav.svelte";
  import { cn } from "$lib/utils/ui.js";
  import LogoIcon from "$lib/components/logo-icon.svelte";
  import { Link } from "@wjfe/n-savant";
  import { getRouteByKey } from "../constants";

  let { children, isOnboarding }: { children: Snippet; isOnboarding: boolean } = $props();

  const getRoute = getRouteByKey("home");
</script>

<main class="layout-container">
  {#if !isOnboarding}
    <div class="border-b px-4">
      <div class="flex h-16 items-center px-4">
        <Link href={getRoute.href} class="flex items-center justify-center mr-4">
          <LogoIcon class="h-8 text-primary" />
        </Link>

        <Nav />

        <div class="ml-auto flex items-center space-x-4"></div>
      </div>
    </div>
  {/if}

  <div class={cn("app-container content-container relative", isOnboarding ? "mx-auto mt-0" : "mb-16 mt-8")}>
    <div class="relative pb-24 px-8">
      {@render children()}
    </div>
  </div>
</main>

<style>
  .layout-container {
    min-height: 100vh;
  }

  .content-container {
    max-width: 1280px;
  }
</style>
