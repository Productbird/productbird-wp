<script lang="ts" generics="TData = any">
  import * as Alert from "$lib/components/ui/alert";
  import { Pulse } from "$lib/components/ui/pulse";
  import type { CreateQueryResult } from "@tanstack/svelte-query";
  import type { WithElementRef, WithoutChildren } from "bits-ui";
  import TriangleAlertIcon from "@lucide/svelte/icons/triangle-alert";
  import type { Snippet } from "svelte";
  import type { HTMLAttributes } from "svelte/elements";

  import { Button } from "$lib/components/ui/button";
  import { cn } from "$lib/utils/ui.js";

  type QueryWrapperChildrenProps = {
    data: TData;
  };

  let {
    ref = $bindable(null),
    class: className,
    asPage = false,
    forceFullPage = false,
    query,
    children,
    showRefetchLoading = false,
    ...restProps
  }: WithoutChildren<WithElementRef<HTMLAttributes<HTMLDivElement>>> & {
    // biome-ignore lint/suspicious/noExplicitAny: <explanation>
    query: CreateQueryResult<TData, any>;
    asPage?: boolean;
    forceFullPage?: boolean;
    children: Snippet<[QueryWrapperChildrenProps]>;
    showRefetchLoading?: boolean;
  } = $props();

  let showLoading = $state(false);
  let loadingTimeout: ReturnType<typeof setTimeout>;

  /**
   * Delay in milliseconds before showing the loading state.
   * This is to prevent flickering when the data is loaded quickly.
   */
  const SHOW_LOADING_DELAY = 500;

  // Track actual loading state separately from displayed loading state
  let isLoading = $derived(query?.isLoading || (showRefetchLoading && query?.isFetching));
  let isError = $derived(query?.isError);

  // Set up delayed loading state
  $effect(() => {
    if (isLoading) {
      loadingTimeout = setTimeout(() => {
        showLoading = true;
      }, SHOW_LOADING_DELAY);
    } else {
      clearTimeout(loadingTimeout);
      showLoading = false;
    }
  });

  function onRetry() {
    query.refetch();
  }
</script>

<div
  class={cn(
    asPage && "flex flex-1 flex-col gap-4",
    asPage && (isLoading || isError) && "flex h-[90dvh] w-full flex-row justify-center",
    forceFullPage && "flex h-[90dvh] w-full flex-row justify-center",
    className
  )}
  {...restProps}
>
  {#if query.error}
    {@const error = query.error}

    <div class="flex flex-col items-center justify-center gap-2">
      <Alert.Root variant="destructive" class="max-w-screen-md">
        <TriangleAlertIcon class="size-5" />

        <Alert.Title class="text-base font-medium">We had trouble loading this resource</Alert.Title>

        <Alert.Description class="mt-2 flex flex-col gap-2">
          <p>{error.message ?? "An unexpected error occurred."}</p>

          {#if error.docs}
            <p class="text-sm">
              Need help? <a href={error.docs} class="font-medium underline" target="_blank" rel="noopener noreferrer"
                >View documentation</a
              >
            </p>
          {/if}

          <div class="mt-2">
            <Button variant="secondary" size="sm" onclick={onRetry}>Try Again</Button>
          </div>
        </Alert.Description>
      </Alert.Root>
    </div>
  {:else if showLoading}
    <div class="flex w-full flex-row items-center justify-center gap-2">
      <Pulse class="text-foreground size-3" />

      <p class="text-muted-foreground text-center text-sm">Loading...</p>
    </div>
  {:else if query.data}
    {@render children?.({
      data: query.data,
    })}
  {/if}
</div>
