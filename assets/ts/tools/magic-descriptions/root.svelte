<script lang="ts">
  import { Toaster } from "$lib/components/ui/sonner/index.js";
  import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/svelte-query";
  import { toast } from "svelte-sonner";
  import ProductDescriptionBulkModal from "./magic-descriptions-bulk-modal.svelte";
  import "$lib/styles/app.pcss";
  import { onMount } from "svelte";
  import { PRODUCT_DESCRIPTION_GLOBALS } from "./utils";
  import type { ProductId } from "$lib/utils/types";

  // Internal state for selected product IDs
  let selectedIds = $state<ProductId[]>([]);
  let open = $state(false);

  const queryClient = new QueryClient({
    queryCache: new QueryCache({
      onError: (error) => {
        if (import.meta.env.DEV) {
          toast.error(`API Query Error: ${error.message}`);
        } else {
          toast.error("An error occurred while fetching data. Please try again.");
        }
      },
    }),
    mutationCache: new MutationCache({
      onError: (error, _, __, mutation) => {
        // cache-level mutations error handler
        const { mutationKey } = mutation.options;

        if (import.meta.env.DEV) {
          console.error(error);
          toast.error(`API Mutation Error ${mutationKey ? `: ${mutationKey}` : ""}`);
        } else {
          toast.error("An error occurred while performing this action. Please try again.");
        }
      },
    }),
  });

  function interceptBulkAction(): void {
    const form = document.getElementById("posts-filter") as HTMLFormElement | null;
    if (!form) return;

    form.addEventListener("submit", (event) => {
      const actionSelector = form.querySelector<HTMLSelectElement>("select[name='action']");
      const action = actionSelector?.value;

      if (action !== "productbird_magic_descriptions") {
        return; // Let WordPress handle other actions normally.
      }

      // Prevent WordPress from submitting the form and refreshing the page.
      event.preventDefault();

      // Collect selected product IDs from the checkboxes.
      const checkboxes = form.querySelectorAll<HTMLInputElement>("input[name='post[]']:checked");
      const ids = Array.from(checkboxes).map((cb) => Number.parseInt(cb.value, 10));

      if (ids.length === 0) {
        alert("Please select at least one product before running Productbird AI.");
        return;
      }

      const maxBatch = PRODUCT_DESCRIPTION_GLOBALS.max_batch;

      if (ids.length > maxBatch) {
        alert(
          `Productbird can only process up to ${maxBatch} products at once. Please select fewer products and try again.`
        );
        return;
      }

      // Update internal state and open modal
      selectedIds = ids as ProductId[];
      open = true;
    });
  }

  function disableBulkActionGroupOption(): void {
    const labelToDisable = PRODUCT_DESCRIPTION_GLOBALS.config?.bulk_action_group_label;
    if (!labelToDisable) return;

    const selectors = ['select[name="action"]', 'select[name="action2"]'];
    for (const selector of selectors) {
      const selectElements = document.querySelectorAll<HTMLSelectElement>(selector);

      for (const selectElement of selectElements) {
        const optionToDisable = selectElement.querySelector<HTMLOptionElement>(`option[value="${labelToDisable}"]`);

        if (optionToDisable) {
          optionToDisable.disabled = true;
          optionToDisable.style.color = "#999";
          optionToDisable.style.fontStyle = "italic";
        }
      }
    }
  }

  onMount(() => {
    interceptBulkAction();
    disableBulkActionGroupOption();
  });
</script>

<Toaster position="top-center" offset={36} />

<QueryClientProvider client={queryClient}>
  <ProductDescriptionBulkModal {selectedIds} bind:open />
</QueryClientProvider>
