<script lang="ts" module>
  export type ProductDescriptionBulkModalProps = {
    selectedIds: ProductId[];
    open: boolean;
  };

  export const STEPS = {
    confirm: "confirm",
    review: "review",
  } as const;

  export const MODE = {
    autoApply: "auto-apply",
    review: "review",
  } as const;

  export type Steps = (typeof STEPS)[keyof typeof STEPS];
  export type Mode = (typeof MODE)[keyof typeof MODE];
</script>

<script lang="ts">
  import * as Dialog from "$lib/components/ui/dialog/index.js";
  import { Button } from "$lib/components/ui/button/index.js";
  import * as RadioGroup from "$lib/components/ui/radio-group/index.js";
  import * as Card from "$lib/components/ui/card/index.js";
  import * as Tabs from "$lib/components/ui/tabs/index.js";
  import { Progress } from "$lib/components/ui/progress/index.js";
  import { ScrollArea } from "$lib/components/ui/scroll-area/index.js";

  import { __ } from "@wordpress/i18n";
  import {
    useGenerateMagicDescriptionsBulk,
    useApplyProductDescription,
    useDeclineProductDescription,
    useRegenerateProductDescription,
  } from "$lib/hooks/queries";
  import { Label } from "$lib/components/ui/label";
  import { rawRequest } from "$lib/utils/api";
  import { createQuery } from "@tanstack/svelte-query";
  import type { QueryClient } from "@tanstack/svelte-query";
  import { Badge } from "$lib/components/ui/badge";
  import { Check, X, RotateCcw, Clock, CheckCircle2, ChevronLeft, ChevronRight, ExternalLink } from "@lucide/svelte";
  import type {
    MagicDescriptionsBulkWpJsonResponse,
    MagicDescriptionsStatusCheckWpJsonResponse,
    ProductId,
  } from "$lib/utils/types";
  import { cn } from "$lib/utils/ui";
  import LogoIcon from "$lib/components/logo-icon.svelte";
  import { getContext } from "svelte";

  // Props
  let { selectedIds = [], open = $bindable() }: ProductDescriptionBulkModalProps = $props();

  // Get query client for cache invalidation
  const queryClient = getContext("queryClient") as QueryClient;

  let currentStep = $state<Steps>(STEPS.confirm);
  let mode = $state<Mode>(MODE.review);
  let currentReviewIndex = $state(0);
  let session = $state<{
    scheduledItems: MagicDescriptionsBulkWpJsonResponse["scheduled_items"];
    /**
     * Items which have returned from the callback.
     */
    completedItems: MagicDescriptionsStatusCheckWpJsonResponse["completed_items"];
    pendingItems: MagicDescriptionsBulkWpJsonResponse["pending_items"];
  }>({
    scheduledItems: [],
    completedItems: [],
    pendingItems: [],
  });

  // Create a separate state for remaining count to break the circular dependency
  let apiRemainingCount = $state<number | undefined>(undefined);

  const textAreaClasses = "prose prose-p:text-lg prose-ul:text-lg prose-li:text-lg";

  const enablePolling = $derived.by((): boolean => {
    const isInReviewStep = currentStep === STEPS.review;
    const hasScheduledItems = session.scheduledItems.length > 0;

    // Stop polling if we're not in review step or have no scheduled items
    if (!isInReviewStep || !hasScheduledItems) {
      return false;
    }

    // If we have a remaining count from the API, use it
    if (apiRemainingCount !== undefined) {
      return apiRemainingCount > 0;
    }

    // Initially poll if we have scheduled items and are in review step
    return true;
  });

  // Poll for completed descriptions directly in the component
  const statusCheckQuery = createQuery<MagicDescriptionsStatusCheckWpJsonResponse>(() => ({
    queryKey: ["magic-descriptions-status", selectedIds, currentStep],
    queryFn: async () => {
      if (!selectedIds.length || currentStep !== STEPS.review) {
        return { completed_items: [], remaining_count: 0 };
      }

      // Ensure product IDs are properly formatted
      const formattedIds = selectedIds.map((id) => String(id));

      const response = await rawRequest<MagicDescriptionsStatusCheckWpJsonResponse>(
        `productbird/v1/magic-descriptions/status?product_ids=${formattedIds.join(",")}`
      );

      // Log the response for debugging
      console.log("Status check response:", response);

      return response;
    },
    refetchInterval: 2000,
    enabled: enablePolling,
    staleTime: 0, // Always fetch fresh data
  }));

  // Combine pending & completed items but ensure uniqueness by product ID to avoid duplicates
  const reviewableItems = $derived.by(() => {
    // Only keep items that actually contain generated HTML
    const merged = [
      ...session.pendingItems.filter((item: any) => item.html),
      ...session.completedItems.filter((item: any) => item.html),
    ];

    // Deduplicate by `id` – later occurrences are ignored to preserve the first version we received
    const uniqueMap = new Map<string | number, any>();
    for (const item of merged) {
      if (!uniqueMap.has(item.id)) {
        uniqueMap.set(item.id, item);
      }
    }

    return Array.from(uniqueMap.values());
  });

  // Calculate progress
  const totalItems = $derived(selectedIds.length);
  const acceptedCount = $derived(reviewableItems.filter((item) => item.status === "accepted").length);

  // Count declined items from server status
  const declinedCount = $derived(reviewableItems.filter((item) => item.status === "declined").length);

  // Remaining to accept (ignores declined for now)
  const remainingToReview = $derived(Math.max(0, totalItems - (acceptedCount + declinedCount)));

  // Progress is based on accepted items only
  const progressPercentage = $derived(totalItems > 0 ? (acceptedCount / totalItems) * 100 : 0);

  // Still rendering/generating items from the API
  const remainingCount = $derived(
    apiRemainingCount !== undefined ? apiRemainingCount : Math.max(0, totalItems - reviewableItems.length)
  );

  // Current item being reviewed
  const currentReviewItem = $derived(reviewableItems[currentReviewIndex]);
  const hasNextItem = $derived(currentReviewIndex < reviewableItems.length - 1);
  const hasPreviousItem = $derived(currentReviewIndex > 0);

  // Add derived state for current item status
  const currentItemStatus = $derived.by(() => {
    if (!currentReviewItem) return "pending";
    return currentReviewItem.status || "pending";
  });

  const generateMagicDescriptionsBulkMutation = useGenerateMagicDescriptionsBulk();
  const applyProductDescriptionMutation = useApplyProductDescription();
  const declineProductDescriptionMutation = useDeclineProductDescription();
  /**
   * For now we're not using this query, just in here for reference.
   */
  const regenerateProductDescriptionMutation = useRegenerateProductDescription();

  async function handleStartGeneration() {
    try {
      // Ensure we're not in a pending state
      if (generateMagicDescriptionsBulkMutation.isPending) {
        console.warn("Generation already in progress");
        return;
      }

      const data = await generateMagicDescriptionsBulkMutation.mutateAsync({
        productIds: selectedIds,
        mode,
      });

      // Store the scheduled items from the response
      if (data && data.scheduled_items) {
        session.scheduledItems = data.scheduled_items;
      }

      // Store any pending items that need review
      if (data && data.pending_items) {
        session.pendingItems = data.pending_items;
      }

      // Force update the step
      currentStep = STEPS.review;
    } catch (error) {
      console.error("Failed to start generation:", error);

      // Check if it's an API error with a response
      if (error && typeof error === "object" && "message" in error) {
        console.error("Error message:", error.message);
      }

      // You might want to show a toast notification here
      // But don't prevent moving to review if we have some items
      if (session.scheduledItems.length > 0 || session.pendingItems.length > 0) {
        currentStep = STEPS.review;
      }
    }
  }

  // Update session when polling data changes
  $effect(() => {
    if (statusCheckQuery.data?.completed_items) {
      // Only add new items, don't replace existing ones
      const existingIds = new Set(session.completedItems.map((item) => item.id));
      const newItems = statusCheckQuery.data.completed_items.filter((item) => !existingIds.has(item.id));

      if (newItems.length > 0) {
        console.log(`Adding ${newItems.length} new completed items`);
        session.completedItems = [...session.completedItems, ...newItems];
      }

      // Update the remaining count
      if (statusCheckQuery.data.remaining_count !== undefined) {
        apiRemainingCount = statusCheckQuery.data.remaining_count;
      }
    }
  });

  // Reset modal state when closed
  $effect(() => {
    if (!open) {
      // Reset all state when modal is closed
      currentStep = STEPS.confirm;
      currentReviewIndex = 0;
      session = {
        scheduledItems: [],
        completedItems: [],
        pendingItems: [],
      };
      apiRemainingCount = undefined;
      console.log("Modal closed - state reset");
    }
  });

  // Reset review index when modal opens
  $effect(() => {
    if (open && currentStep === STEPS.review) {
      currentReviewIndex = 0;
    }
  });

  // Debug current step changes
  $effect(() => {
    if (open) {
      console.log("Modal state:", {
        currentStep,
        scheduledItems: session.scheduledItems.length,
        completedItems: session.completedItems.length,
        pendingItems: session.pendingItems.length,
        enablePolling,
        apiRemainingCount,
      });
    }
  });

  // Debug template rendering
  $effect(() => {
    console.log("Template check - currentStep:", currentStep);
    console.log("Template check - is confirm?", currentStep === STEPS.confirm);
    console.log("Template check - is review?", currentStep === STEPS.review);
  });

  // Navigation functions
  function goToNextItem() {
    if (hasNextItem) {
      currentReviewIndex++;
    }
  }

  function goToPreviousItem() {
    if (hasPreviousItem) {
      currentReviewIndex--;
    }
  }

  // Placeholder functions for actions (not hooked up as requested)
  function handleAcceptDescription(productId: ProductId) {
    // Guard against duplicate accept
    if (currentItemStatus === "accepted") return;

    const item = reviewableItems.find((i) => i.id === productId);
    if (!item) return;

    // Ensure description is always a string
    const descriptionToApply: string = item.html ?? "";

    // Apply description via API
    applyProductDescriptionMutation
      .mutateAsync({ productId, description: descriptionToApply })
      .then(() => {
        // Invalidate and refetch the status query
        queryClient.invalidateQueries({ queryKey: ["magic-descriptions-status"] });

        // Auto-advance to next item
        if (hasNextItem) {
          goToNextItem();
        }
      })
      .catch((err) => {
        console.error("Failed to apply description", err);
      });
  }

  function handleDeclineDescription(productId: ProductId) {
    if (currentItemStatus === "declined") return;

    // Decline description via API
    declineProductDescriptionMutation
      .mutateAsync({ productId })
      .then(() => {
        // Invalidate and refetch the status query
        queryClient.invalidateQueries({ queryKey: ["magic-descriptions-status"] });

        // Auto-advance to next item after action
        if (hasNextItem) {
          goToNextItem();
        }
      })
      .catch((err) => {
        console.error("Failed to decline description", err);
      });
  }

  function handleRegenerateDescription(_productId: ProductId) {
    alert(__("Unfortunately regenerate is not available yet. Please try again later.", "productbird"));
  }
</script>

{#snippet htmlTextArea(label: string, description: string, color?: "emerald" | "gray")}
  <div class="space-y-3">
    <Label
      class={cn(
        "text-sm font-medium",
        color === "emerald" ? "text-emerald-600 dark:text-emerald-400" : "text-gray-600 dark:text-gray-400"
      )}
    >
      {label}
    </Label>

    <div
      class={cn(
        "border rounded-lg",
        color === "emerald"
          ? "border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/30"
          : "border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-950/30"
      )}
    >
      <ScrollArea class="h-[300px] p-4">
        <div class={cn(textAreaClasses)}>
          {@html description}
        </div>
      </ScrollArea>
    </div>
  </div>
{/snippet}

{#snippet stepTitle(title: string)}
  <div class="flex items-center gap-2">
    <LogoIcon class="h-6 w-6 text-primary" />
    <h2 class="text-xl font-bold">{title}</h2>
  </div>
{/snippet}

<Dialog.Root bind:open>
  <Dialog.Content
    interactOutsideBehavior="ignore"
    class={cn(
      "max-w-screen-xl max-h-[700px] overflow-hidden flex flex-col",
      currentStep === STEPS.confirm && "max-w-screen-sm"
    )}
  >
    {#if currentStep === STEPS.confirm}
      <Dialog.Header>
        <Dialog.Title>{@render stepTitle(__("Generate Descriptions with AI", "productbird"))}</Dialog.Title>

        <Dialog.Description>
          {__("You are about to generate descriptions for", "productbird")}
          {selectedIds.length}
          {__("products", "productbird")}.
        </Dialog.Description>
      </Dialog.Header>

      <div class="space-y-4 py-4">
        <RadioGroup.Root bind:value={mode}>
          <div class="space-y-6">
            <div class="flex gap-2">
              <RadioGroup.Item value="review" id="review" />
              <Label for="review" class="flex flex-col gap-2">
                {__("Review & Approve", "productbird")}

                <small>
                  {__("Review each description before applying it to the product.", "productbird")}
                </small>
              </Label>
            </div>

            <div class="flex gap-2">
              <RadioGroup.Item value="auto-apply" id="auto-apply" />
              <Label for="auto-apply" class="flex flex-col gap-2">
                <div>
                  {__("Auto Apply", "productbird")}

                  <Badge variant="outline" class="ml-2">
                    {__("YOLO mode", "productbird")}
                  </Badge>
                </div>

                <small>
                  {__(
                    "With YOLO mode, all generated descriptions will be applied to products without review.",
                    "productbird"
                  )}
                </small>
              </Label>
            </div>
          </div>
        </RadioGroup.Root>
      </div>

      <Dialog.Footer>
        <Button variant="outline" onclick={() => (open = false)}>
          {__("Cancel", "productbird")}
        </Button>

        <Button onclick={handleStartGeneration} disabled={generateMagicDescriptionsBulkMutation.isPending}>
          {generateMagicDescriptionsBulkMutation.isPending
            ? __("Starting...", "productbird")
            : __("Start Generation", "productbird")}
        </Button>
      </Dialog.Footer>
    {:else if currentStep === STEPS.review}
      <Dialog.Header class="flex-shrink-0">
        <Dialog.Title class="flex items-center justify-between">
          {@render stepTitle(__("Review Generated Descriptions", "productbird"))}

          <!-- Information bar-->
          <div class="flex items-center justify-end gap-2">
            <div class="w-full max-w-[12rem] flex-shrink-0 flex-row items-center">
              <div class="flex sr-only items-center justify-between text-sm text-muted-foreground">
                <span>{__("Progress", "productbird")}</span>
                <span>{Math.round(progressPercentage)}%</span>
              </div>
              <Progress value={progressPercentage} class="h-2" />
            </div>

            <Badge variant="secondary" class="flex-shrink-0">
              {acceptedCount} / {totalItems}
              {__("accepted", "productbird")}
            </Badge>
          </div>
        </Dialog.Title>

        <Dialog.Description class="space-y-3">
          <p>{__("Review and approve the AI-generated descriptions for your products.", "productbird")}</p>

          {#if remainingCount > 0}
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
              <Clock class="h-4 w-4" />
              <span>{remainingCount} {__("descriptions still being generated...", "productbird")}</span>
            </div>
          {/if}
        </Dialog.Description>
      </Dialog.Header>

      <div class="flex-1 overflow-hidden">
        {#if reviewableItems.length === 0}
          <div class="flex items-center justify-center h-full">
            <Card.Root class="max-w-md w-full">
              <Card.Content class="flex items-center justify-center py-12">
                <div class="text-center space-y-2">
                  <p class="text-muted-foreground">
                    {__("No descriptions available yet.", "productbird")}
                  </p>
                </div>
              </Card.Content>
            </Card.Root>
          </div>
        {:else if currentReviewItem}
          <!-- Navigation Header -->
          <div class="flex items-center justify-between p-4 flex-shrink-0">
            <div class="flex items-center gap-4">
              <Badge variant="outline" class="text-sm">
                {__("Item", "productbird")}
                {currentReviewIndex + 1}
                {__("of", "productbird")}
                {reviewableItems.length}
              </Badge>
              <div class="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="default"
                  onclick={goToPreviousItem}
                  disabled={!hasPreviousItem}
                  class="flex items-center gap-1"
                >
                  <ChevronLeft class="h-4 w-4" />
                  {__("Previous", "productbird")}
                </Button>
                <Button
                  variant="outline"
                  size="default"
                  onclick={goToNextItem}
                  disabled={!hasNextItem}
                  class="flex items-center gap-1"
                >
                  {__("Next", "productbird")}
                  <ChevronRight class="h-4 w-4" />
                </Button>
              </div>
            </div>
            {#if currentItemStatus === "accepted"}
              <Badge
                variant="outline"
                class="flex items-center gap-1 text-emerald-600 border-emerald-200 bg-emerald-50 dark:text-emerald-400 dark:border-emerald-800 dark:bg-emerald-950/30"
              >
                <Check class="h-3 w-3" />
                {__("Accepted", "productbird")}
              </Badge>
            {:else if currentItemStatus === "declined"}
              <Badge
                variant="outline"
                class="flex items-center gap-1 text-red-600 border-red-200 bg-red-50 dark:text-red-400 dark:border-red-800 dark:bg-red-950/30"
              >
                <X class="h-3 w-3" />
                {__("Declined", "productbird")}
              </Badge>
            {:else}
              <Badge variant="outline" class="flex items-center gap-1">
                <CheckCircle2 class="h-3 w-3" />
                {__("Ready for review", "productbird")}
              </Badge>
            {/if}
          </div>

          <!-- Content Area -->
          <div class="flex-1 min-h-0">
            <ScrollArea class="p-0 h-[460px]">
              <Card.Root class="overflow-hidden">
                <Card.Content class="space-y-6">
                  <Tabs.Root value="preview" class="w-full">
                    <div class="flex justify-between items-center gap-4">
                      <div class="flex-shrink-0 items-start justify-between">
                        <h3 class="text-lg font-bold">{currentReviewItem.name}</h3>
                        <p class="text-sm text-muted-foreground flex items-center gap-1">
                          {__("Product ID", "productbird")}: {currentReviewItem.id}
                          <a
                            href={`${window.productbird.admin_url}/post.php?post=${currentReviewItem.id}&action=edit`}
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-primary hover:text-primary/80 inline-flex"
                          >
                            <ExternalLink class="h-4 w-4" />
                          </a>
                        </p>
                      </div>

                      <div class="max-w-[200px]">
                        <Tabs.List class="grid w-full grid-cols-2 flex-1">
                          <Tabs.Trigger value="preview">{__("Preview", "productbird")}</Tabs.Trigger>
                          <Tabs.Trigger value="comparison">{__("Compare", "productbird")}</Tabs.Trigger>
                        </Tabs.List>
                      </div>
                    </div>

                    <Tabs.Content value="comparison" class="mt-6">
                      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                        <!-- Current Description -->
                        {@render htmlTextArea(
                          __("Current Description", "productbird"),
                          currentReviewItem.current_html ?? "",
                          "gray"
                        )}

                        <!-- Generated Description -->
                        {@render htmlTextArea(
                          __("Generated Description", "productbird"),
                          currentReviewItem.html ?? "",
                          "emerald"
                        )}
                      </div>
                    </Tabs.Content>

                    <Tabs.Content value="preview" class="mt-6">
                      {@render htmlTextArea(
                        __("Generated Description", "productbird"),
                        currentReviewItem.html ?? "",
                        "emerald"
                      )}
                    </Tabs.Content>
                  </Tabs.Root>
                </Card.Content>
              </Card.Root>
            </ScrollArea>
          </div>
        {/if}
      </div>

      <Dialog.Footer class="flex-shrink-0">
        <div class="flex items-center justify-between w-full">
          <Button variant="outline" onclick={() => (open = false)}>
            {__("Close", "productbird")}
          </Button>

          <div class="flex gap-2">
            {#if currentReviewItem}
              <!-- Individual item actions -->
              <div class="flex gap-2">
                <Button
                  size="default"
                  variant="outline"
                  onclick={() => handleRegenerateDescription(currentReviewItem.id)}
                  class="flex items-center gap-2"
                  disabled={currentItemStatus === "accepted"}
                >
                  <RotateCcw class="h-4 w-4" />
                  {__("Regenerate", "productbird")}
                </Button>

                <Button
                  size="default"
                  variant="outline"
                  onclick={() => handleDeclineDescription(currentReviewItem.id)}
                  class="flex items-center gap-2 text-destructive hover:text-destructive/75 hover:bg-destructive/10"
                  disabled={currentItemStatus === "accepted" || currentItemStatus === "declined"}
                >
                  <X class="h-4 w-4" />
                  {currentItemStatus === "declined" ? __("Declined", "productbird") : __("Decline", "productbird")}
                </Button>

                <Button
                  size="default"
                  onclick={() => handleAcceptDescription(currentReviewItem.id)}
                  loading={applyProductDescriptionMutation.isPending}
                  class="flex items-center gap-2"
                  disabled={currentItemStatus === "declined" || currentItemStatus === "accepted"}
                >
                  <Check class="h-4 w-4" />
                  {currentItemStatus === "accepted"
                    ? __("Accepted ✓", "productbird")
                    : __("Accept & Apply", "productbird")}
                </Button>
              </div>
            {:else if acceptedCount > 0}
              <!-- Bulk actions when no current item -->
              <div class="flex gap-2">
                <Button
                  variant="outline"
                  onclick={() => {
                    // Accept all remaining reviewable items in bulk
                    reviewableItems.forEach((item) => {
                      if (item.status !== "accepted") {
                        handleAcceptDescription(item.id as ProductId);
                      }
                    });
                  }}
                  disabled={reviewableItems.length === 0}
                >
                  {__("Accept All", "productbird")} ({totalItems - remainingToReview})
                </Button>
                <Button
                  variant="outline"
                  onclick={() => {
                    // Decline all remaining reviewable items in bulk
                    reviewableItems.forEach((item) => {
                      if (item.status !== "declined") {
                        handleDeclineDescription(item.id as ProductId);
                      }
                    });
                  }}
                  disabled={reviewableItems.length === 0}
                >
                  {__("Decline All", "productbird")} ({totalItems - remainingToReview})
                </Button>
              </div>
            {/if}
          </div>
        </div>
      </Dialog.Footer>
    {/if}
  </Dialog.Content>
</Dialog.Root>
