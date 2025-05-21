<script lang="ts">
  import { __ } from "@wordpress/i18n";
  import Layout from "./components/layout.svelte";
  import Form from "./components/form.svelte";
  import QueryWrapper from "$lib/components/query-wrapper.svelte";
  import { location } from "@wjfe/n-savant";
  import { getRouteByKey } from "./constants";
  import { useGetSettings } from "$lib/hooks/queries";

  const onboardingRoute = getRouteByKey("onboarding");
  const isOnboarding = $derived(location.hashPaths.single === onboardingRoute.path);
  const settingsQuery = useGetSettings();
</script>

<Layout {isOnboarding}>
  <QueryWrapper query={settingsQuery} asPage={true}>
    {#snippet children({ data })}
      <Form initialData={data} />
    {/snippet}
  </QueryWrapper>
</Layout>
