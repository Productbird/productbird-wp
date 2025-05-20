<script lang="ts">
  import { __ } from "@wordpress/i18n";

  import Layout from "./components/layout.svelte";
  import Form from "./components/form.svelte";
  import QueryWrapper from "$lib/components/query-wrapper.svelte";
  import { getSettings } from "$lib/utils/api";
  import { createQuery } from "@tanstack/svelte-query";
  import { location } from "@wjfe/n-savant";
  import { getRouteByKey } from "./constants";

  const onboardingRoute = getRouteByKey("onboarding");
  const isOnboarding = $derived(location.hashPaths.single === onboardingRoute.path);
  const settingsQuery = createQuery(() => ({
    queryKey: ["settings"],
    queryFn: async () => await getSettings(),
  }));
</script>

<Layout {isOnboarding}>
  <QueryWrapper query={settingsQuery}>
    {#snippet children({ data })}
      <Form initialData={data} />
    {/snippet}
  </QueryWrapper>
</Layout>
