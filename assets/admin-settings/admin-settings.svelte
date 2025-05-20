<script lang="ts">
  import { Router, Route, Fallback, RouterTrace, init, location } from "@wjfe/n-savant";
  import NotFound from "./routes/not-found.svelte";

  import "$lib/styles/app.pcss";
  import Layout from "./components/layout.svelte";
  import { routerConfig, getRouteByKey } from "./constants";

  init({ implicitMode: "hash" });

  const showTracer = false;
  const onboardingRoute = getRouteByKey("onboarding");
  const isOnboarding = $derived(location.hashPaths.single === onboardingRoute.path);
</script>

<Router id="root-router">
  <Layout {isOnboarding}>
    {#each routerConfig as { key, path, component: Component }}
      <Route {key} {path}>
        <Component />
      </Route>
    {/each}

    <Fallback>
      <NotFound />
    </Fallback>
  </Layout>

  {#if showTracer}
    <RouterTrace />
  {/if}
</Router>
