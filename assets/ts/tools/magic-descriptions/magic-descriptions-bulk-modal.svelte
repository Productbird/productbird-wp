<script lang="ts" module>
  export type ProductDescriptionBulkModalProps = {
    selectedIds: ProductId[];
    open: boolean;
  };

  export const PRODUCT_DESCRIPTION_BULK_MODAL_STEPS = {
    confirm: "confirm",
    review: "review",
  } as const;

  export const PRODUCT_DESCRIPTION_BULK_MODAL_MODE = {
    autoApply: "auto-apply",
    review: "review",
  } as const;
  export type ProductDescriptionBulkModalSteps =
    (typeof PRODUCT_DESCRIPTION_BULK_MODAL_STEPS)[keyof typeof PRODUCT_DESCRIPTION_BULK_MODAL_STEPS];
  export type ProductDescriptionBulkModalMode =
    (typeof PRODUCT_DESCRIPTION_BULK_MODAL_MODE)[keyof typeof PRODUCT_DESCRIPTION_BULK_MODAL_MODE];
</script>

<script lang="ts">
  import { onMount, onDestroy } from "svelte";
  import * as Dialog from "$lib/components/ui/dialog/index.js";
  import { Button } from "$lib/components/ui/button/index.js";
  import * as RadioGroup from "$lib/components/ui/radio-group/index.js";
  import * as Card from "$lib/components/ui/card/index.js";
  import * as Tabs from "$lib/components/ui/tabs/index.js";
  import { Progress } from "$lib/components/ui/progress/index.js";
  import { Separator } from "$lib/components/ui/separator/index.js";
  import { ScrollArea } from "$lib/components/ui/scroll-area/index.js";
  import { __ } from "@wordpress/i18n";
  import {
    useGenerateMagicDescriptionsBulk,
    useApplyProductDescription,
    useRegenerateProductDescription,
  } from "$lib/hooks/queries";
  import { Label } from "$lib/components/ui/label";
  import { rawRequest } from "$lib/utils/api";
  import { createQuery } from "@tanstack/svelte-query";
  import { Badge } from "$lib/components/ui/badge";
  import { Check, X, RotateCcw, Clock, CheckCircle2, ChevronLeft, ChevronRight } from "@lucide/svelte";
  import type {
    MagicDescriptionsBulkWpJsonResponse,
    MagicDescriptionsStatusCheckWpJsonResponse,
    ProductId,
  } from "$lib/utils/types";

  // Props
  let { selectedIds = [], open = $bindable() }: ProductDescriptionBulkModalProps = $props();

  let currentStep = $state<ProductDescriptionBulkModalSteps>(PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.confirm);
  let mode = $state<ProductDescriptionBulkModalMode>(PRODUCT_DESCRIPTION_BULK_MODAL_MODE.review);
  let currentReviewIndex = $state(0);
  let session = $state<{
    scheduledItems: MagicDescriptionsBulkWpJsonResponse["scheduled_items"];
    /**
     * Items which have returned from the callback.
     */
    completedItems: MagicDescriptionsStatusCheckWpJsonResponse["completed_items"];
    pendingItems: MagicDescriptionsBulkWpJsonResponse["pending_items"];
    /**
     * Product IDs the merchant has accepted & applied in this session.
     */
    acceptedIds: ProductId[];
    /**
     * Product IDs the merchant has declined in this session.
     */
    declinedIds: ProductId[];
  }>({
    scheduledItems: [],
    completedItems: [],
    pendingItems: [],
    acceptedIds: [],
    declinedIds: [],
  });

  // Create a separate state for remaining count to break the circular dependency
  let apiRemainingCount = $state<number | undefined>(undefined);

  const enablePolling = $derived.by((): boolean => {
    const isInReviewStep = currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review;
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
      if (!selectedIds.length || currentStep !== PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review) {
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

  const reviewableItems = $derived.by(() => {
    const pendingItems = session.pendingItems.filter((item: any) => item.html);
    const completedItems = session.completedItems.filter((item: any) => item.html);

    return [...pendingItems, ...completedItems];
  });

  // Calculate progress
  const totalItems = $derived(selectedIds.length);
  const acceptedCount = $derived(session.acceptedIds.length);

  // Remaining to accept (ignores declined for now)
  const remainingToReview = $derived(Math.max(0, totalItems - (acceptedCount + session.declinedIds.length)));

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

  const generateMagicDescriptionsBulkMutation = useGenerateMagicDescriptionsBulk();
  const applyProductDescriptionMutation = useApplyProductDescription();
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
      currentStep = PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review;
    } catch (error) {
      console.error("Failed to start generation:", error);

      // Check if it's an API error with a response
      if (error && typeof error === "object" && "message" in error) {
        console.error("Error message:", error.message);
      }

      // You might want to show a toast notification here
      // But don't prevent moving to review if we have some items
      if (session.scheduledItems.length > 0 || session.pendingItems.length > 0) {
        currentStep = PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review;
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
      currentStep = PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.confirm;
      currentReviewIndex = 0;
      session = {
        scheduledItems: [],
        completedItems: [],
        pendingItems: [],
        acceptedIds: [],
        declinedIds: [],
      };
      apiRemainingCount = undefined;
      console.log("Modal closed - state reset");
    }
  });

  // Reset review index when modal opens
  $effect(() => {
    if (open && currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review) {
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
    console.log("Template check - is confirm?", currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.confirm);
    console.log("Template check - is review?", currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review);
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
    if (session.acceptedIds.includes(productId)) return;

    const item = reviewableItems.find((i) => i.id === productId);
    if (!item) return;

    // Ensure description is always a string
    const descriptionToApply: string = item.html ?? "";

    // Apply description via API
    applyProductDescriptionMutation
      .mutateAsync({ productId, description: descriptionToApply })
      .then(() => {
        session.acceptedIds = [...session.acceptedIds, productId];

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
    if (session.declinedIds.includes(productId)) return;

    session.declinedIds = [...session.declinedIds, productId];

    // Auto-advance to next item after action
    if (hasNextItem) {
      goToNextItem();
    }
  }

  function handleRegenerateDescription(productId: ProductId) {
    console.log("Regenerate description for product:", productId);
  }
</script>

<Dialog.Root bind:open>
  <Dialog.Content
    interactOutsideBehavior="ignore"
    escapeKeydownBehavior="ignore"
    class="max-w-screen-xl min-h-0 max-h-[85vh] overflow-hidden flex flex-col"
  >
    {#if currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.confirm}
      <Dialog.Header>
        <Dialog.Title>
          {__("Generate Descriptions with AI", "productbird")}
        </Dialog.Title>
        <Dialog.Description>
          {__("You are about to generate descriptions for", "productbird")}
          {selectedIds.length}
          {__("products", "productbird")}.
        </Dialog.Description>
      </Dialog.Header>

      <div class="mt-4 space-y-4">
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
    {:else if currentStep === PRODUCT_DESCRIPTION_BULK_MODAL_STEPS.review}
      <Dialog.Header class="flex-shrink-0">
        <Dialog.Title class="flex items-center justify-between">
          <span>{__("Review Generated Descriptions", "productbird")}</span>
          <Badge variant="secondary" class="ml-auto">
            {acceptedCount} / {totalItems}
            {__("accepted", "productbird")}
          </Badge>
        </Dialog.Title>
        <Dialog.Description class="space-y-3">
          <p>{__("Review and approve the AI-generated descriptions for your products.", "productbird")}</p>

          <div class="space-y-2">
            <div class="flex items-center justify-between text-sm text-muted-foreground">
              <span>{__("Progress", "productbird")}</span>
              <span>{Math.round(progressPercentage)}%</span>
            </div>
            <Progress value={progressPercentage} class="h-2" />
          </div>

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
          <div class="h-full flex flex-col">
            <!-- Navigation Header -->
            <div class="flex items-center justify-between p-4 border-b bg-muted/30">
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
                    size="sm"
                    onclick={goToPreviousItem}
                    disabled={!hasPreviousItem}
                    class="flex items-center gap-1"
                  >
                    <ChevronLeft class="h-4 w-4" />
                    {__("Previous", "productbird")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onclick={goToNextItem}
                    disabled={!hasNextItem}
                    class="flex items-center gap-1"
                  >
                    {__("Next", "productbird")}
                    <ChevronRight class="h-4 w-4" />
                  </Button>
                </div>
              </div>
              <Badge variant="outline" class="flex items-center gap-1">
                <CheckCircle2 class="h-3 w-3" />
                {__("Ready for review", "productbird")}
              </Badge>
            </div>

            <!-- Content Area -->
            <ScrollArea class="flex-1">
              <div class="p-6">
                <Card.Root class="overflow-hidden">
                  <Card.Header class="pb-3">
                    <div class="flex items-start justify-between">
                      <div>
                        <Card.Title class="text-xl">{currentReviewItem.name}</Card.Title>
                        <p class="text-sm text-muted-foreground">Product ID: {currentReviewItem.id}</p>
                      </div>
                    </div>
                  </Card.Header>

                  <Card.Content class="space-y-6">
                    <Tabs.Root value="preview" class="w-full">
                      <Tabs.List class="grid w-full grid-cols-2">
                        <Tabs.Trigger value="preview">{__("Preview", "productbird")}</Tabs.Trigger>
                        <Tabs.Trigger value="comparison">{__("Compare", "productbird")}</Tabs.Trigger>
                      </Tabs.List>

                      <Tabs.Content value="comparison" class="mt-6">
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                          <!-- Current Description -->
                          <div class="space-y-3">
                            <Label class="text-sm font-medium text-muted-foreground">
                              {__("Current Description", "productbird")}
                            </Label>
                            <div class="border rounded-lg p-4 bg-muted/30 min-h-[200px]">
                              <p class="text-sm text-muted-foreground italic">
                                {__("Loading current description...", "productbird")}
                              </p>
                            </div>
                          </div>

                          <!-- Generated Description -->
                          <div class="space-y-3">
                            <Label class="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                              {__("AI Generated Description", "productbird")}
                            </Label>
                            <div
                              class="border border-emerald-200 dark:border-emerald-800 rounded-lg p-4 bg-emerald-50/50 dark:bg-emerald-950/30 min-h-[200px]"
                            >
                              <div class="prose prose-sm max-w-none">
                                {@html currentReviewItem.html}
                              </div>
                            </div>
                          </div>
                        </div>
                      </Tabs.Content>

                      <Tabs.Content value="preview" class="mt-6">
                        <div class="border rounded-lg p-6 bg-background min-h-[200px]">
                          <div class="prose prose-sm max-w-none">
                            {@html currentReviewItem.html}
                          </div>
                        </div>
                      </Tabs.Content>
                    </Tabs.Root>
                  </Card.Content>
                </Card.Root>
              </div>
            </ScrollArea>

            <!-- Action Bar -->
            <div class="border-t bg-background p-4">
              <div class="flex items-center justify-between">
                <div class="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onclick={() => handleRegenerateDescription(currentReviewItem.id)}
                    class="flex items-center gap-2"
                  >
                    <RotateCcw class="h-4 w-4" />
                    {__("Regenerate", "productbird")}
                  </Button>
                </div>

                <div class="flex gap-3">
                  <Button
                    size="sm"
                    variant="outline"
                    onclick={() => handleDeclineDescription(currentReviewItem.id)}
                    class="flex items-center gap-2 text-destructive hover:text-destructive-foreground hover:bg-destructive"
                  >
                    <X class="h-4 w-4" />
                    {__("Decline", "productbird")}
                  </Button>
                  <Button
                    size="default"
                    onclick={() => handleAcceptDescription(currentReviewItem.id)}
                    class="flex items-center gap-2"
                  >
                    <Check class="h-4 w-4" />
                    {__("Accept & Apply", "productbird")}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        {/if}
      </div>

      <Dialog.Footer class="flex-shrink-0">
        <div class="flex items-center justify-between w-full">
          <Button variant="outline" onclick={() => (open = false)}>
            {__("Close", "productbird")}
          </Button>

          <div class="flex gap-2">
            {#if acceptedCount > 0}
              <Button
                variant="outline"
                onclick={() => {
                  // Accept all remaining reviewable items in bulk
                  reviewableItems.forEach((item) => {
                    if (!session.acceptedIds.includes(item.id)) {
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
                    if (!session.declinedIds.includes(item.id)) {
                      handleDeclineDescription(item.id as ProductId);
                    }
                  });
                }}
                disabled={reviewableItems.length === 0}
              >
                {__("Decline All", "productbird")} ({totalItems - remainingToReview})
              </Button>
            {/if}
          </div>
        </div>
      </Dialog.Footer>
    {/if}
  </Dialog.Content>
</Dialog.Root>
