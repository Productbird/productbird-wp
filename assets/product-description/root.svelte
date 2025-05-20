<script lang="ts">
  import "$lib/styles/app.pcss";
  import { Toaster } from "$lib/components/ui/sonner/index.js";
  import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/svelte-query";
  import { toast } from "svelte-sonner";
  import ProductDescriptionBulkModal from "./product-description-bulk-modal.svelte";

  let { selectedIds = [] }: { selectedIds: number[] } = $props();

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
</script>

<Toaster position="top-center" offset={36} />

<QueryClientProvider client={queryClient}>
  <ProductDescriptionBulkModal {selectedIds} />
</QueryClientProvider>
