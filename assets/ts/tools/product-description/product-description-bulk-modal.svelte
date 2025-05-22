<script lang="ts">
  import "$lib/styles/app.pcss";
  import { onMount, onDestroy } from "svelte";
  import * as Dialog from "$lib/components/ui/dialog/index.js";
  import { Button } from "$lib/components/ui/button/index.js";
  import * as RadioGroup from "$lib/components/ui/radio-group/index.js";
  import { Progress } from "$lib/components/ui/progress/index.js";
  import { __ } from "@wordpress/i18n";
  import {
    useGenerateProductDescriptionsBulk,
    useApplyProductDescription,
    useRegenerateProductDescription,
  } from "$lib/hooks/queries";
  import { Label } from "$lib/components/ui/label";
  import { rawRequest } from "$lib/utils/api";
  import { createQuery } from "@tanstack/svelte-query";

  // Props
  let { selectedIds = [] }: { selectedIds: number[] } = $props();

  // State variables
  let open = $state(true);
  let currentStep = $state("confirm"); // confirm, processing, review
  let mode = $state<"auto-apply" | "review">("review");
  let batchId = $state<string | null>(null);

  // Session state
  let session = $state({
    productIds: selectedIds,
    statusMap: new Map<number, string>(), // productId -> status (queued, completed, applied, rejected)
    completedDescriptions: new Map<number, string>(), // productId -> description
    productNames: new Map<number, string>(), // productId -> product name
    currentReviewIndex: 0,
    totalCompleted: 0,
    totalApplied: 0,
    totalRejected: 0,
  });

  // Current product being reviewed
  const currentProduct = $derived.by(() => {
    if (currentStep !== "review") return null;

    const completedProductIds = Array.from(session.completedDescriptions.keys()).filter(
      (id) => session.statusMap.get(id) === "completed"
    );

    if (completedProductIds.length === 0) return null;

    const productId = completedProductIds[session.currentReviewIndex];
    if (!productId) return null;

    return {
      id: productId,
      name: session.productNames.get(productId) || `#${productId}`,
      description: session.completedDescriptions.get(productId) || "",
    };
  });

  const completionPercentage = $derived.by(() => {
    const total = session.productIds.length;
    const processed = session.totalCompleted + session.totalApplied + session.totalRejected;
    return Math.floor((processed / total) * 100);
  });

  // Set up mutations
  const generateMutation = useGenerateProductDescriptionsBulk();
  const applyMutation = useApplyProductDescription();
  const regenerateMutation = useRegenerateProductDescription();

  // Only poll when we're in the processing or review state
  const shouldPoll = $derived(currentStep === "processing" || currentStep === "review");
  const completedQuery = createQuery(() => ({
    queryKey: ["completed-descriptions", selectedIds],
    queryFn: async () => {
      if (!selectedIds.length) return { completed: [], remaining: 0 };

      return await rawRequest(`productbird/v1/description-completed?productIds=${selectedIds.join(",")}`);
    },
    refetchInterval: 5000, // Poll every 5 seconds
    enabled: selectedIds.length > 0 && shouldPoll,
  }));

  // Persist session to localStorage
  function persistSession() {
    if (!batchId) return;
    localStorage.setItem(
      `productbird_batch_${batchId}`,
      JSON.stringify({
        productIds: session.productIds,
        statusMap: Array.from(session.statusMap.entries()),
        completedDescriptions: Array.from(session.completedDescriptions.entries()),
        productNames: Array.from(session.productNames.entries()),
        currentReviewIndex: session.currentReviewIndex,
        totalCompleted: session.totalCompleted,
        totalApplied: session.totalApplied,
        totalRejected: session.totalRejected,
        mode,
        step: currentStep,
      })
    );
  }

  // Try to restore session from localStorage
  function tryRestoreSession() {
    // Check for any active batches in localStorage
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (!key || !key.startsWith("productbird_batch_")) continue;

      // Found a batch, restore it
      try {
        const savedData = JSON.parse(localStorage.getItem(key) || "");
        if (!savedData) continue;

        // Only restore if it contains our current products
        const storedIds = new Set(savedData.productIds);
        const currentIds = new Set(selectedIds);

        // Compare sets
        if (selectedIds.length !== savedData.productIds.length) continue;
        if (!selectedIds.every((id) => storedIds.has(id))) continue;

        // Restore the session
        session.productIds = savedData.productIds;
        session.statusMap = new Map(savedData.statusMap);
        session.completedDescriptions = new Map(savedData.completedDescriptions);
        session.productNames = new Map(savedData.productNames ?? []);
        session.currentReviewIndex = savedData.currentReviewIndex;
        session.totalCompleted = savedData.totalCompleted;
        session.totalApplied = savedData.totalApplied;
        session.totalRejected = savedData.totalRejected;

        // Restore the mode and step
        mode = savedData.mode;
        currentStep = savedData.step;

        // Extract batch ID from key
        batchId = key.replace("productbird_batch_", "");

        return true;
      } catch (e) {
        console.error("Failed to restore session:", e);
      }
    }

    return false;
  }

  // Start the generation process
  async function startGeneration() {
    try {
      // Create a unique batch ID if we don't have one
      if (!batchId) {
        batchId = `batch_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
      }

      // Move to processing step
      currentStep = "processing";

      // Start the generation
      await generateMutation.mutateAsync({
        productIds: selectedIds,
        mode,
      });

      // Update status map with queued status for all products
      for (const productId of selectedIds) {
        session.statusMap.set(productId, "queued");
      }

      // Persist the session
      persistSession();
    } catch (error) {
      console.error("Failed to start generation:", error);
      // TODO: Show error message
    }
  }

  // Handle query data updates
  $effect(() => {
    if (!completedQuery.data) return;

    const { completed = [], remaining = 0 } = completedQuery.data as {
      completed: Array<{ productId: number; descriptionHtml: string; productName?: string }>;
      remaining: number;
    };

    // Process completed items if any
    if (completed && completed.length > 0) {
      for (const item of completed) {
        // Skip if we've already processed this item
        if (session.statusMap.get(item.productId) === "completed") continue;

        // Update status and description
        session.statusMap.set(item.productId, "completed");
        session.completedDescriptions.set(item.productId, item.descriptionHtml);
        if (item.productName) {
          session.productNames.set(item.productId, item.productName);
        }
        session.totalCompleted++;

        // If in auto-apply mode, automatically apply the description
        if (mode === "auto-apply") {
          applyDescription(item.productId);
        }
      }
    }

    // If we're processing and all items are completed, move to review step
    if (currentStep === "processing" && remaining === 0 && completed.length > 0) {
      if (mode === "review") {
        currentStep = "review";
      } else {
        // For auto-apply, we're done once everything is applied
        const allApplied = selectedIds.every((id) => session.statusMap.get(id) === "applied");

        if (allApplied) {
          onFinish();
        }
      }
    }

    // Persist session state
    persistSession();
  });

  // Apply a description to a product
  async function applyDescription(productId: number) {
    try {
      const description = session.completedDescriptions.get(productId);
      if (!description) return;

      await applyMutation.mutateAsync({
        productId,
        description,
      });

      // Update status and counters
      session.statusMap.set(productId, "applied");
      session.totalApplied++;
      session.totalCompleted = Math.max(0, session.totalCompleted - 1);

      // Move to next item if we're reviewing
      if (currentStep === "review") {
        moveToNextItem();
      }

      // Persist session
      persistSession();
    } catch (error) {
      console.error("Failed to apply description:", error);
      // TODO: Show error message
    }
  }

  // Reject a description
  function rejectDescription(productId: number) {
    // Update status and counters
    session.statusMap.set(productId, "rejected");
    session.totalRejected++;
    session.totalCompleted = Math.max(0, session.totalCompleted - 1);

    // Move to next item
    moveToNextItem();

    // Persist session
    persistSession();
  }

  // Regenerate a description
  async function regenerateDescription(productId: number, customPrompt?: string) {
    try {
      // Update status back to queued
      session.statusMap.set(productId, "queued");
      session.totalCompleted--;

      // Request regeneration
      await regenerateMutation.mutateAsync({
        productId,
        customPrompt,
      });

      // Don't move to next item, we'll wait for the regenerated description

      // Persist session
      persistSession();
    } catch (error) {
      console.error("Failed to regenerate description:", error);
      // TODO: Show error message
    }
  }

  // Move to the next item in the review queue
  function moveToNextItem() {
    const completedProductIds = Array.from(session.completedDescriptions.keys()).filter(
      (id) => session.statusMap.get(id) === "completed"
    );

    if (completedProductIds.length === 0) {
      // Check if we're done
      const allProcessed = selectedIds.every((id) => ["applied", "rejected"].includes(session.statusMap.get(id) || ""));

      if (allProcessed) {
        onFinish();
      }

      return;
    }

    // Move to next or wrap around
    session.currentReviewIndex = (session.currentReviewIndex + 1) % completedProductIds.length;
  }

  // Clean up and close the modal
  function onFinish() {
    // Clean up localStorage
    if (batchId) {
      localStorage.removeItem(`productbird_batch_${batchId}`);
    }

    // Close the modal
    open = false;
  }

  // Check for existing session on mount
  onMount(() => {
    const restored = tryRestoreSession();
    if (!restored && selectedIds.length > 0) {
      // No existing session, start a new one
      session.productIds = selectedIds;
    }
  });

  // Clean up on component destroy
  onDestroy(() => {
    // Persist session in case user refreshes or navigates away
    persistSession();
  });
</script>

<Dialog.Root bind:open>
  <Dialog.Content
    interactOutsideBehavior="ignore"
    escapeKeydownBehavior="ignore"
    class="max-w-screen-lg min-h-0 max-h-[80vh] overflow-y-auto"
  >
    {#if currentStep === "confirm"}
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
              <RadioGroup.Item value="auto-apply" id="auto-apply" />
              <Label for="auto-apply" class="flex flex-col gap-2">
                {__("Auto Apply", "productbird")}

                <small>
                  {__("Automatically apply all generated descriptions to products without review.", "productbird")}
                </small>
              </Label>
            </div>

            <div class="flex gap-2">
              <RadioGroup.Item value="review" id="review" />
              <Label for="review" class="flex flex-col gap-2">
                {__("Review & Approve", "productbird")}

                <small>
                  {__("Review each description before applying it to the product.", "productbird")}
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
        <Button onclick={startGeneration} disabled={generateMutation.isPending}>
          {generateMutation.isPending ? __("Starting...", "productbird") : __("Start Generation", "productbird")}
        </Button>
      </Dialog.Footer>
    {/if}

    {#if currentStep === "processing"}
      <Dialog.Header>
        <Dialog.Title>
          {__("Generating Descriptions", "productbird")}
        </Dialog.Title>
        <Dialog.Description>
          {__("Please wait while we generate descriptions for your products.", "productbird")}
        </Dialog.Description>
      </Dialog.Header>

      <div class="mt-6 space-y-4">
        <Progress value={completionPercentage} />
        <p class="text-center text-sm text-muted-foreground">
          {completionPercentage}% {__("complete", "productbird")} ({session.totalCompleted}
          {__("of", "productbird")}
          {selectedIds.length})
        </p>
      </div>

      <Dialog.Footer>
        <Button variant="outline" onclick={() => (open = false)}>
          {__("Close & Continue in Background", "productbird")}
        </Button>
      </Dialog.Footer>
    {/if}

    {#if currentStep === "review" && currentProduct}
      <Dialog.Header>
        <Dialog.Title>
          {__("Review Descriptions", "productbird")}
        </Dialog.Title>
        <Dialog.Description>
          {__("Review and approve each generated description.", "productbird")}
        </Dialog.Description>
      </Dialog.Header>

      <div class="mt-6 space-y-4">
        <div class="bg-muted rounded-md p-4">
          <h3 class="font-medium mb-2">{currentProduct.name}</h3>
          <div class="prose prose-sm max-w-none">{@html currentProduct.description}</div>
        </div>
      </div>

      <Dialog.Footer>
        <div class="flex w-full justify-between">
          <div>
            <Button
              variant="destructive"
              onclick={() => rejectDescription(currentProduct?.id || 0)}
              disabled={!currentProduct}
            >
              {__("Reject", "productbird")}
            </Button>
          </div>

          <div class="flex gap-2">
            <Button
              variant="outline"
              onclick={() => regenerateDescription(currentProduct?.id || 0)}
              disabled={!currentProduct || regenerateMutation.isPending}
            >
              {regenerateMutation.isPending ? __("Regenerating...", "productbird") : __("Regenerate", "productbird")}
            </Button>

            <Button
              variant="default"
              onclick={() => applyDescription(currentProduct?.id || 0)}
              disabled={!currentProduct || applyMutation.isPending}
            >
              {applyMutation.isPending ? __("Applying...", "productbird") : __("Apply", "productbird")}
            </Button>
          </div>
        </div>
      </Dialog.Footer>
    {/if}
  </Dialog.Content>
</Dialog.Root>

<style>
  :global(.woocommerce-layout__header) {
    z-index: 10;
  }
</style>
